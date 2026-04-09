<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

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
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;

abstract class AbstractMigrationTask extends BuildTask
{
    /** @return list<InputOption> */
    public function getOptions(): array
    {
        return [
            new InputOption('default-viewport', null, InputOption::VALUE_REQUIRED, 'Old module default viewport (e.g. MD)'),
            new InputOption('zone', null, InputOption::VALUE_REQUIRED, 'Target zone for new Sections (e.g. main)'),
            new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Log what would be migrated without writing'),
            new InputOption('force', 'f', InputOption::VALUE_NONE, 'Skip the interactive confirmation prompt (required for non-interactive runs)'),
            new InputOption('viewport-map', null, InputOption::VALUE_REQUIRED, 'Comma-separated old=new viewport key pairs'),
            new InputOption('page-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated page IDs to migrate'),
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

        /** @var string $defaultViewport */
        /** @var string $zone */

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

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
            ? \array_map('intval', \explode(',', $pageIdsArg))
            : null;

        /** @var GridAdapterInterface $adapter */
        $adapter = Injector::inst()->get(GridAdapterInterface::class);

        $viewportKeyMap = $this->resolveViewportKeyMap(
            \is_string($input->getOption('viewport-map')) ? $input->getOption('viewport-map') : null,
            $adapter,
        );

        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper(columnCount: $adapter->getColumnCount(), logger: $logger);
        $grouper = new ElementGrouper();
        $strategy = $this->createStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger);

        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);
        $failures = $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds);

        if ($failures > 0) {
            $output->writeln(\sprintf('%d page(s) failed to migrate. Check logs for details.', $failures));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $viewportKeyMap
     */
    abstract protected function createStrategy(
        ElementGrouper $grouper,
        FieldMapper $mapper,
        string $defaultViewport,
        array $viewportKeyMap,
        LoggerInterface $logger,
    ): RowMappingStrategy;

    /**
     * Parse viewport-map arg or derive from adapter.
     *
     * @return array<string, string> old key → new key
     */
    protected function resolveViewportKeyMap(?string $viewportMapArg, GridAdapterInterface $adapter): array
    {
        if ($viewportMapArg !== null && $viewportMapArg !== '') {
            $map = [];
            foreach (\explode(',', $viewportMapArg) as $pair) {
                $parts = \explode('=', $pair, 2);
                if (\count($parts) === 2) {
                    $map[\trim($parts[0])] = \trim($parts[1]);
                }
            }
            return $map;
        }

        $viewports = $adapter->getViewports();
        $oldKeys = ['XS', 'SM', 'MD', 'LG', 'XL'];
        $map = [];
        foreach ($oldKeys as $oldKey) {
            foreach ($viewports as $viewport) {
                if (\strtolower($oldKey) === \strtolower($viewport->key)) {
                    $map[$oldKey] = $viewport->key;
                    break;
                }
            }
        }
        return $map;
    }
}
