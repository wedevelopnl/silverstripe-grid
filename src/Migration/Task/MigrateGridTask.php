<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;

/**
 * Migrates legacy Elemental content to the grid hierarchy on non-localised
 * (or single-locale) sites. Refuses to run when localised legacy tables are
 * present — those must use {@see MigrateGridWithFluentTask}.
 */
class MigrateGridTask extends AbstractMigrationTask
{
    protected static string $commandName = 'migrate-grid';

    protected string $title = 'Migrate grid';

    protected static string $description = 'Migrates old elemental-grid data into the Section/Row/Column hierarchy. Use --strategy=sections (default) or --strategy=single-section.';

    protected function preflight(): ?string
    {
        if ((new LegacyLocalisationDetector())->hasLocalisedContent()) {
            return 'Localised legacy Elemental tables detected (Fluent). Run "migrate-grid-with-fluent" instead — '
                . 'this task would migrate content without locale context and lose per-locale data.';
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
    ): int {
        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);

        return $service->run($defaultViewport, $zone, $viewportKeyMap, $dryRun, $pageIds);
    }
}
