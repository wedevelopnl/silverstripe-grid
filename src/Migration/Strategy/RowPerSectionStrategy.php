<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Value\GridSettings;

/**
 * Maps each row group to its own Section containing exactly one Row.
 *
 * This is the default strategy when each legacy row should become
 * an independent section in the new grid hierarchy.
 */
final class RowPerSectionStrategy implements RowMappingStrategy
{
    /**
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'MD' → 'md')
     */
    public function __construct(
        private readonly ElementGrouper $grouper,
        private readonly FieldMapper $mapper,
        private readonly string $defaultViewport,
        private readonly array $viewportKeyMap,
    ) {}

    /**
     * @param list<LegacyElement> $elements Flat sorted element list
     * @param int $pageId Target page ID for parent relationships
     * @param string $zone Target zone
     * @return list<MigrationSection>
     */
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

    /**
     * Group consecutive elements with identical GridSettings into shared columns.
     *
     * @param list<LegacyElement> $elements
     * @return list<MigrationColumn>
     */
    private function buildColumns(array $elements): array
    {
        /** @var list<GridSettings> $groupSettings */
        $groupSettings = [];
        /** @var list<list<LegacyElement>> $groupElements */
        $groupElements = [];

        foreach ($elements as $element) {
            $gridSettings = $this->mapper->mapGridSettings($element, $this->defaultViewport, $this->viewportKeyMap);
            $lastIndex = \count($groupSettings) - 1;

            if ($lastIndex >= 0 && $gridSettings->equals($groupSettings[$lastIndex])) {
                $groupElements[$lastIndex][] = $element;
            } else {
                $groupSettings[] = $gridSettings;
                $groupElements[] = [$element];
            }
        }

        $columns = [];
        foreach ($groupSettings as $index => $settings) {
            $columns[] = new MigrationColumn(
                gridSettings: $settings,
                sort: $index + 1,
                elements: $groupElements[$index],
            );
        }

        return $columns;
    }
}
