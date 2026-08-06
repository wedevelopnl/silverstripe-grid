<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;

/**
 * Maps each row group to its own Section containing exactly one Row.
 *
 * This is the default strategy when each legacy row should become
 * an independent section in the new grid hierarchy.
 */
final readonly class RowPerSectionStrategy extends AbstractRowMappingStrategy
{
    public function buildHierarchy(array $elements, int $pageId, string $zone): array
    {
        $groups = $this->grouper->group($elements);
        $sections = [];
        $sectionSort = 1;

        foreach ($groups as $group) {
            $row = $group['row'];
            $rowData = $group['rowData'];

            $extraClass = $rowData !== null ? $rowData->customSectionClass : '';
            $rowTitle = $row !== null ? $row->title : '';
            $rowExtraClass = $row !== null ? $row->extraClass : '';

            $columns = $this->buildColumns($group['elements']);

            // A legacy row delimiter with no following content (consecutive
            // delimiters, or a trailing empty row — common on legacy sites) yields
            // no columns. Writing it produces an empty Section→Row with no Column,
            // violating the complete-hierarchy invariant every other write path
            // guarantees. Skip it; sectionSort stays contiguous.
            if ($columns === []) {
                continue;
            }

            $migrationRow = new MigrationRow(
                title: $rowTitle,
                extraClass: $rowExtraClass,
                sort: 1,
                columns: $columns,
            );

            $sections[] = new MigrationSection(
                title: '',
                zone: $zone,
                extraClass: $extraClass,
                sort: $sectionSort,
                rows: [$migrationRow],
            );

            $sectionSort++;
        }

        return $sections;
    }
}
