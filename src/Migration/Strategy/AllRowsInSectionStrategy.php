<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;

/**
 * Maps all row groups to Rows under a single Section.
 *
 * Use this when the source page has multiple rows that logically
 * belong to one section. Section-level fields (isFluid, extraClass)
 * are taken from the first explicit row; later rows with conflicting
 * values trigger a warning and are discarded.
 */
final class AllRowsInSectionStrategy implements RowMappingStrategy
{
    /**
     * @param array<string, string> $viewportKeyMap Old viewport key → new key (e.g. 'MD' → 'md')
     */
    public function __construct(
        private readonly ElementGrouper $grouper,
        private readonly FieldMapper $mapper,
        private readonly string $defaultViewport,
        private readonly array $viewportKeyMap,
        private readonly LoggerInterface $logger,
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

        if ($groups === []) {
            return [];
        }

        // Determine section-level fields from the first explicit row group.
        // Implicit groups (no row element) contribute no section-level data.
        // Note: IsFluid is not migrated — it's a class-level config on Section,
        // not a per-instance field. Only customSectionClass is carried over.
        $sectionExtraClass = '';
        $sectionFieldsSet = false;

        $rows = [];
        $rowSort = 1;

        foreach ($groups as $group) {
            $row = $group['row'];
            $rowData = $group['rowData'];

            if ($rowData !== null) {
                if (!$sectionFieldsSet) {
                    $sectionExtraClass = $rowData->customSectionClass;
                    $sectionFieldsSet = true;
                } else {
                    if ($rowData->customSectionClass !== $sectionExtraClass) {
                        $this->logger->warning(
                            'AllRowsInSectionStrategy: row customSectionClass conflicts with section value; discarding row value.',
                            ['rowId' => $row?->id, 'rowClass' => $rowData->customSectionClass, 'sectionClass' => $sectionExtraClass],
                        );
                    }
                }
            }

            $columns = $this->buildColumns($group['elements']);

            $rows[] = new MigrationRow(
                title: $row !== null ? $row->title : '',
                extraClass: $row !== null ? $row->extraClass : '',
                sort: $rowSort,
                columns: $columns,
            );

            $rowSort++;
        }

        return [
            new MigrationSection(
                title: '',
                zone: $zone,
                extraClass: $sectionExtraClass,
                sort: 1,
                rows: $rows,
            ),
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @return list<MigrationColumn>
     */
    private function buildColumns(array $elements): array
    {
        $columns = [];
        $columnSort = 1;

        foreach ($elements as $element) {
            $gridSettings = $this->mapper->mapGridSettings($element, $this->defaultViewport, $this->viewportKeyMap);

            $columns[] = new MigrationColumn(
                gridSettings: $gridSettings,
                sort: $columnSort,
                element: $element,
            );

            $columnSort++;
        }

        return $columns;
    }
}
