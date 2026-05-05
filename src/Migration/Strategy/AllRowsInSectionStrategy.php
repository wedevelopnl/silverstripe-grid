<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Value\GridSettings;

/**
 * Maps all row groups to Rows under a single Section.
 *
 * Use this when the source page has multiple rows that logically
 * belong to one section. Section-level fields (extraClass)
 * are taken from the first explicit row; later rows with conflicting
 * values trigger a warning and are discarded.
 */
final readonly class AllRowsInSectionStrategy implements RowMappingStrategy
{
    /**
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'MD' → 'md')
     */
    public function __construct(
        private ElementGrouper $grouper,
        private FieldMapper $mapper,
        private string $defaultViewport,
        private array $viewportKeyMap,
        private LoggerInterface $logger,
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
        $rows = $this->buildRows($groups);

        if ($rows === []) {
            return [];
        }

        return [
            new MigrationSection(
                title: '',
                zone: $zone,
                extraClass: $this->resolveSectionExtraClass($groups),
                sort: 1,
                rows: $rows,
            ),
        ];
    }

    /**
     * Resolve section-level extraClass from the first explicit row group.
     *
     * Implicit groups (no row element) contribute no section-level data.
     * IsFluid is not migrated — it's a class-level config on Section,
     * not a per-instance field. Only customSectionClass is carried over.
     *
     * @param list<array{row: ?LegacyElement, rowData: ?LegacyRowData, elements: list<LegacyElement>}> $groups
     */
    private function resolveSectionExtraClass(array $groups): string
    {
        $sectionExtraClass = '';
        $resolved = false;

        foreach ($groups as $group) {
            $rowData = $group['rowData'];

            if ($rowData === null) {
                continue;
            }

            if (!$resolved) {
                $sectionExtraClass = $rowData->customSectionClass;
                $resolved = true;

                continue;
            }

            if ($rowData->customSectionClass !== $sectionExtraClass) {
                $this->logger->warning(
                    'AllRowsInSectionStrategy: row customSectionClass conflicts with section value; discarding row value.',
                    ['rowId' => $group['row']?->id, 'rowClass' => $rowData->customSectionClass, 'sectionClass' => $sectionExtraClass],
                );
            }
        }

        return $sectionExtraClass;
    }

    /**
     * @param list<array{row: ?LegacyElement, rowData: ?LegacyRowData, elements: list<LegacyElement>}> $groups
     * @return list<MigrationRow>
     */
    private function buildRows(array $groups): array
    {
        $rows = [];
        $rowSort = 1;

        foreach ($groups as $group) {
            $row = $group['row'];

            $rows[] = new MigrationRow(
                title: $row !== null ? $row->title : '',
                extraClass: $row !== null ? $row->extraClass : '',
                sort: $rowSort,
                columns: $this->buildColumns($group['elements']),
            );

            $rowSort++;
        }

        return $rows;
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
