<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Migration\Strategy\RowMappingStrategy;

class MigrateRowsToSingleSectionTask extends AbstractMigrationTask
{
    protected static string $commandName = 'migrate-grid-rows-to-single-section';

    protected string $title = 'Migrate grid rows to single section';

    protected static string $description = 'Migrates old elemental-grid data: all ElementRows become Rows under a single Section per page.';

    protected function createStrategy(
        ElementGrouper $grouper,
        FieldMapper $mapper,
        string $defaultViewport,
        array $viewportKeyMap,
        LoggerInterface $logger,
    ): RowMappingStrategy {
        return new AllRowsInSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap, $logger);
    }
}
