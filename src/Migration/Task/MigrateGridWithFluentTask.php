<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use RuntimeException;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
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
        try {
            (new LegacyLocalisationDetector())->detect();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
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
    ): int {
        $orchestrator = new FluentMigrationOrchestrator(
            $reader,
            new LegacyLocalisationDetector(),
            $mapper,
            $strategy,
            $logger,
        );

        return $orchestrator->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds, $stopOnFirstFailure);
    }
}
