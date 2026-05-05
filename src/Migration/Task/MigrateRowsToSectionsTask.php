<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;

class MigrateRowsToSectionsTask extends AbstractMigrationTask
{
    protected static string $commandName = 'migrate-grid-rows-to-sections';

    protected string $title = 'Migrate grid rows to sections';

    protected static string $description = 'Migrates old elemental-grid data: each ElementRow becomes a Section + Row in the new hierarchy.';

    protected function createStrategy(
        ElementGrouper $grouper,
        FieldMapper $mapper,
        string $defaultViewport,
        array $viewportKeyMap,
        LoggerInterface $logger,
    ): RowMappingStrategy {
        return new RowPerSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap);
    }
}
