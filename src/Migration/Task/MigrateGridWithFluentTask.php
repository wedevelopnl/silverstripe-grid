<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use RuntimeException;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\DroppedMediaCollector;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Service\LegacyTables;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;

/**
 * Migrates legacy Elemental content per locale on Fluent sites. Detects the
 * legacy localisation model from DB shape and runs the migration once per
 * locale (default first) inside FluentState.
 */
class MigrateGridWithFluentTask extends AbstractMigrationTask
{
    protected static string $commandName = 'migrate-grid-with-fluent';

    protected string $title = 'Migrate grid (Fluent)';

    protected static string $description = 'Migrates old elemental-grid data per locale on Fluent sites. Use --strategy=sections (default) or --strategy=single-section.';

    protected function preflight(): ?string
    {
        if (!class_exists(FluentState::class)) {
            return 'tractorcow/silverstripe-fluent is not installed; cannot migrate per locale. '
                . 'Install Fluent, or use "migrate-grid" for a single-locale site.';
        }

        // Resolve the localisation model now — before the destructive-write
        // confirmation prompt. detect() throws on an ambiguous mixed Fluent
        // config; without this, that throw would only fire later inside the
        // orchestrator (after the operator confirmed) and escape run() as a
        // raw stack trace instead of a clean Command::FAILURE.
        $detector = new LegacyLocalisationDetector();
        try {
            $detector->detect();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        // Without the per-locale ElementalAreaID every locale would silently
        // read the base column: one locale's blocks, copied nowhere else.
        $setAside = $detector->setAsideAreaTables();
        if ($setAside !== []) {
            return \implode(' ', \array_map($this->setAsideAreaError(...), $setAside));
        }

        // Require a resolvable default locale. Fluent's Locale::getDefault()
        // returns null only when no locales exist at all (with one or more
        // configured it falls back to the first), so a null here means the site
        // has no locales. The orchestrator builds its per-locale plan from the
        // default locale plus the cached locales, so a null default yields an
        // EMPTY plan: every per-locale pass is skipped, the grid-disabled
        // reconciliation never runs, and the task would report SUCCESS having
        // migrated nothing. Fail loudly instead of silently no-opping.
        if (Locale::getDefault() === null) {
            return 'No Fluent default locale resolves; configure at least one locale '
                . '(and a global default) before migrating per locale, or use '
                . '"migrate-grid" for a single-locale site.';
        }

        return null;
    }

    private function setAsideAreaError(string $table): string
    {
        $live = LegacyTables::exists('_obsolete_' . $table . '_Localised_Live')
            ? \sprintf(' (and "_obsolete_%1$s_Localised_Live" to "%1$s_Localised_Live")', $table)
            : '';

        return \sprintf(
            'Page table "%1$s": legacy table "_obsolete_%1$s_Localised" holds the per-locale ElementalAreaID '
            . 'that "%1$s_Localised" lacks (set aside by dev/build). Rename it back to "%1$s_Localised"%2$s '
            . 'before migrating; nothing was changed.',
            $table,
            $live,
        );
    }

    protected function performMigration(
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
        DroppedMediaCollector $droppedMedia,
    ): array {
        $orchestrator = new FluentMigrationOrchestrator(
            $reader,
            new LegacyLocalisationDetector(),
            $mapper,
            $strategy,
            $logger,
            $droppedMedia,
        );

        return $orchestrator->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds, $stopOnFirstFailure);
    }
}
