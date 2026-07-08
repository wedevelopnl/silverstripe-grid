<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use InvalidArgumentException;
use Override;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyElementReader;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Value\Viewport;

abstract class AbstractMigrationTask extends BuildTask
{
    /** @return list<InputOption> */
    #[Override]
    public function getOptions(): array
    {
        return [
            new InputOption('default-viewport', null, InputOption::VALUE_REQUIRED, 'Old module default viewport (e.g. MD)'),
            new InputOption('zone', null, InputOption::VALUE_REQUIRED, 'Target zone for new Sections (e.g. main)'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Log what would be migrated without writing'),
            // No short flag: sake reserves "-f" for its global --flush, so a task
            // option claiming "-f" makes Symfony Console throw "An option with
            // shortcut \"f\" already exists" and aborts every `sake dev/tasks/...` run.
            new InputOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation prompt (required for non-interactive runs)'),
            new InputOption('viewport-map', null, InputOption::VALUE_REQUIRED, 'Comma-separated old=new viewport key pairs'),
            new InputOption('page-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated page IDs to migrate'),
            new InputOption('strategy', null, InputOption::VALUE_REQUIRED, 'Row mapping strategy: "sections" (default) or "single-section"', 'sections'),
            new InputOption('stop-on-first-failure', null, InputOption::VALUE_NONE, 'Halt the batch after the first page that fails to migrate'),
        ];
    }

    public function execute(InputInterface $input, PolyOutput $output): int
    {
        $defaultViewport = $input->getOption('default-viewport');
        $zone = $input->getOption('zone');

        if (!$defaultViewport || !$zone) {
            $output->writeln('Required options: --default-viewport, --zone');
            /** @var string $segment */
            $segment = static::config()->get('segment');
            $output->writeln(\sprintf('Usage: sake dev/tasks/%s --default-viewport=MD --zone=main', $segment));
            return Command::FAILURE;
        }

        /** @var non-empty-string $zone */

        // Validate --default-viewport against the legacy key set and normalise to
        // uppercase. Legacy sizeFields are keyed by uppercase XS/SM/MD/LG/XL, so an
        // unvalidated value (e.g. a lowercase "md" typo, the spelling used for the
        // NEW adapter keys) misses the lookup in FieldMapper::mapGridSettings and
        // silently makes every column full-width on a destructive migration.
        $rawDefaultViewport = (string) $defaultViewport;
        $defaultViewport = \strtoupper($rawDefaultViewport);
        if (!\in_array($defaultViewport, LegacyElementReader::VIEWPORT_KEYS, true)) {
            $output->writeln(\sprintf(
                '<error>Invalid --default-viewport "%s". Use one of: %s.</error>',
                $rawDefaultViewport,
                \implode(', ', LegacyElementReader::VIEWPORT_KEYS),
            ));
            return Command::FAILURE;
        }

        /** @var non-empty-string $defaultViewport */

        // Validate --strategy explicitly: an unknown value must fail loudly rather
        // than silently fall through to the default and write a different hierarchy
        // on a destructive migration (e.g. a "--strategy=single" typo).
        /** @var string $strategyName */
        $strategyName = $input->getOption('strategy') ?: 'sections';
        if (!\in_array($strategyName, ['sections', 'single-section'], true)) {
            $output->writeln(\sprintf('<error>Invalid --strategy "%s". Use "sections" or "single-section".</error>', $strategyName));
            return Command::FAILURE;
        }

        // Preflight checks (DB / class existence) run before the destructive-write
        // confirmation so operators are not asked to confirm and then immediately
        // refused. Both implementations depend only on DB table shape or class
        // existence, not on the adapter, viewport map, or page-ids resolved below.
        $preflightError = $this->preflight();
        if ($preflightError !== null) {
            $output->writeln(\sprintf('<error>%s</error>', $preflightError));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $stopOnFirstFailure = (bool) $input->getOption('stop-on-first-failure');

        if (!$dryRun && !$force) {
            if (!$input->isInteractive()) {
                $output->writeln('<error>Refusing to run: migration is destructive. Pass --dry-run to preview, or --force to run non-interactively.</error>');
                return Command::FAILURE;
            }

            $question = new ConfirmationQuestion(
                'This will write Section/Row/Column elements derived from the legacy Elemental tables. Continue? [y/N] ',
                false,
            );

            if (!(new QuestionHelper())->ask($input, $output, $question)) {
                $output->writeln('Migration aborted.');
                return Command::SUCCESS;
            }
        }

        $pageIdsArg = $input->getOption('page-ids');
        $pageIds = \is_string($pageIdsArg) && $pageIdsArg !== ''
            ? \array_map(intval(...), \explode(',', $pageIdsArg))
            : null;

        $adapter = Injector::inst()->get(GridAdapterInterface::class);

        try {
            $viewportKeyMap = $this->resolveViewportKeyMap(
                \is_string($input->getOption('viewport-map')) ? $input->getOption('viewport-map') : null,
                $adapter,
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(\sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        // An empty map drops every responsive override silently. This happens when
        // the active adapter shares no key names with the legacy set (e.g. Bulma:
        // mobile/tablet/desktop) and no explicit --viewport-map was given.
        if ($viewportKeyMap === []) {
            $output->writeln(\sprintf(
                '<error>Could not derive a viewport map: no legacy key (%s) matches an active adapter viewport (%s). '
                . 'Pass --viewport-map with explicit old=new pairs.</error>',
                \implode(', ', LegacyElementReader::VIEWPORT_KEYS),
                \implode(', ', \array_map(static fn (Viewport $v): string => $v->key, $adapter->getViewports())),
            ));
            return Command::FAILURE;
        }

        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = $this->buildFieldMapper($adapter, $logger);
        $grouper = new ElementGrouper();

        $strategy = $this->createStrategy($strategyName, $grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger);

        $failures = $this->performMigration(
            $reader,
            $mapper,
            $strategy,
            $logger,
            $defaultViewport,
            $zone,
            $viewportKeyMap,
            $dryRun,
            $pageIds,
            $stopOnFirstFailure,
        );

        if ($failures > 0) {
            $output->writeln(\sprintf('%d page(s) failed to migrate. Check logs for details.', $failures));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-string      $defaultViewport
     * @param array<string, string> $viewportKeyMap
     */
    protected function createStrategy(
        string $strategyName,
        ElementGrouper $grouper,
        FieldMapper $mapper,
        string $defaultViewport,
        array $viewportKeyMap,
        LoggerInterface $logger,
    ): RowMappingStrategy {
        return match ($strategyName) {
            'single-section' => new AllRowsInSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger),
            default => new RowPerSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap),
        };
    }

    /**
     * Optional preflight check. Return an error message to abort before any
     * migration runs, or null to proceed. Default: no preflight.
     */
    protected function preflight(): ?string
    {
        return null;
    }

    /**
     * Run the migration. Each concrete task wires the appropriate engine
     * (plain service vs Fluent orchestrator).
     *
     * @param array<string, string> $viewportKeyMap
     * @param list<int>|null $pageIds
     * @return int<0, max> Number of pages that failed to migrate
     */
    abstract protected function performMigration(
        LegacyDataReader $reader,
        FieldMapper $mapper,
        RowMappingStrategy $strategy,
        LoggerInterface $logger,
        string $defaultViewport,
        string $zone,
        array $viewportKeyMap,
        bool $dryRun,
        ?array $pageIds,
        bool $stopOnFirstFailure,
    ): int;

    /**
     * Build the FieldMapper used for the migration, allowing extensions to
     * override any of its four lookup tables via the `updateFieldMapperConfig`
     * hook. Each argument is passed by reference and defaults to `null`, which
     * preserves FieldMapper's built-in defaults.
     *
     * Extension signature:
     * ```
     * public function updateFieldMapperConfig(
     *     ?array &$classNameMap,
     *     ?array &$verticalAlignMap,
     *     ?array &$mediaPositionMap,
     *     ?array &$gapSizeMap,
     * ): void
     * ```
     */
    protected function buildFieldMapper(GridAdapterInterface $adapter, LoggerInterface $logger): FieldMapper
    {
        /** @var array<string, string>|null $classNameMap */
        $classNameMap = null;
        /** @var array<string, string>|null $verticalAlignMap */
        $verticalAlignMap = null;
        /** @var array<string, string>|null $mediaPositionMap */
        $mediaPositionMap = null;
        /** @var array<int, int>|null $gapSizeMap */
        $gapSizeMap = null;

        $this->extend(
            'updateFieldMapperConfig',
            $classNameMap,
            $verticalAlignMap,
            $mediaPositionMap,
            $gapSizeMap,
        );

        // The extend() call passes by reference, which PHPStan has to widen
        // to the looser array shape it cannot introspect across the hook.
        // The extension contract is documented above; re-tag the narrowed
        // types here so the FieldMapper constructor accepts them.
        /** @var array<string, string>|null $classNameMap */
        /** @var array<string, string>|null $verticalAlignMap */
        /** @var array<string, string>|null $mediaPositionMap */
        /** @var array<int, int>|null $gapSizeMap */

        return new FieldMapper(
            classNameMap: $classNameMap,
            verticalAlignMap: $verticalAlignMap,
            mediaPositionMap: $mediaPositionMap,
            gapSizeMap: $gapSizeMap,
            columnCount: $adapter->getColumnCount(),
            logger: $logger,
        );
    }

    /**
     * Parse the --viewport-map arg (validating both sides) or derive the map from
     * the adapter by name-matching legacy keys to adapter viewports.
     *
     * @return array<string, string> old key → new key
     *
     * @throws InvalidArgumentException when an explicit pair names an unknown legacy
     *     old key or an adapter viewport that is not enabled
     */
    protected function resolveViewportKeyMap(?string $viewportMapArg, GridAdapterInterface $adapter): array
    {
        $adapterKeys = \array_map(static fn (Viewport $v): string => $v->key, $adapter->getViewports());

        if ($viewportMapArg !== null && $viewportMapArg !== '') {
            $map = [];
            foreach (\explode(',', $viewportMapArg) as $pair) {
                $parts = \explode('=', $pair, 2);
                if (\count($parts) !== 2) {
                    continue;
                }

                $rawOld = \trim($parts[0]);
                $old = \strtoupper($rawOld);
                $new = \trim($parts[1]);

                if (!\in_array($old, LegacyElementReader::VIEWPORT_KEYS, true)) {
                    throw new InvalidArgumentException(\sprintf(
                        'Invalid --viewport-map old key "%s". Use one of: %s.',
                        $rawOld,
                        \implode(', ', LegacyElementReader::VIEWPORT_KEYS),
                    ));
                }

                if (!\in_array($new, $adapterKeys, true)) {
                    throw new InvalidArgumentException(\sprintf(
                        'Invalid --viewport-map new key "%s". Use one of the active adapter viewports: %s.',
                        $new,
                        \implode(', ', $adapterKeys),
                    ));
                }

                $map[$old] = $new;
            }
            return $map;
        }

        $map = [];
        foreach (LegacyElementReader::VIEWPORT_KEYS as $oldKey) {
            foreach ($adapterKeys as $adapterKey) {
                if (\strtolower($oldKey) === \strtolower($adapterKey)) {
                    $map[$oldKey] = $adapterKey;
                    break;
                }
            }
        }
        return $map;
    }
}
