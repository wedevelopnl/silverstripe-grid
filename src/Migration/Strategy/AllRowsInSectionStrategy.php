<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;

/**
 * Maps all row groups to Rows under a single Section.
 *
 * Use this when the source page has multiple rows that logically
 * belong to one section. Section-level fields (extraClass)
 * are taken from the first explicit row; later rows with conflicting
 * values trigger a warning and are discarded.
 */
final readonly class AllRowsInSectionStrategy extends AbstractRowMappingStrategy
{
    /**
     * @param non-empty-string      $defaultViewport
     * @param array<string, string> $viewportKeyMap  Old viewport key → new key (e.g. 'MD' → 'md')
     */
    public function __construct(
        ElementGrouper $grouper,
        FieldMapper $mapper,
        string $defaultViewport,
        array $viewportKeyMap,
        private LoggerInterface $logger,
    ) {
        parent::__construct($grouper, $mapper, $defaultViewport, $viewportKeyMap);
    }

    public function buildHierarchy(array $elements, int $pageId, string $zone): array
    {
        $groups = $this->grouper->group($elements);
        $rows = $this->buildRows($groups);

        if ($rows === []) {
            return [];
        }

        $sectionRow = $this->resolveSectionRow($groups);

        return [
            new MigrationSection(
                title: '',
                zone: $zone,
                extraClass: $sectionRow?->rowData?->customSectionClass ?? '',
                sort: 1,
                rows: $rows,
                legacyRow: $sectionRow,
            ),
        ];
    }

    /**
     * Resolve the legacy row that section-level fields are taken from: the
     * first explicit row group's row.
     *
     * Implicit groups (no row element) contribute no section-level data.
     * IsFluid is not migrated — it's a class-level config on Section,
     * not a per-instance field. Of the stock fields only customSectionClass
     * is carried over; later rows with a conflicting value are logged.
     *
     * @param list<array{row: ?LegacyElement, rowData: ?LegacyRowData, elements: list<LegacyElement>}> $groups
     */
    private function resolveSectionRow(array $groups): ?LegacyElement
    {
        $sectionRow = null;
        $sectionExtraClass = '';

        foreach ($groups as $group) {
            $rowData = $group['rowData'];

            if ($rowData === null) {
                continue;
            }

            if ($sectionRow === null) {
                $sectionRow = $group['row'];
                $sectionExtraClass = $rowData->customSectionClass;

                continue;
            }

            if ($rowData->customSectionClass !== $sectionExtraClass) {
                $this->logger->warning(
                    'AllRowsInSectionStrategy: row customSectionClass conflicts with section value; discarding row value.',
                    ['rowId' => $group['row']?->id, 'rowClass' => $rowData->customSectionClass, 'sectionClass' => $sectionExtraClass],
                );
            }
        }

        return $sectionRow;
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
            $columns = $this->buildColumns($group['elements']);

            // Skip empty row groups (a delimiter with no following content): an
            // empty Row with no Column violates the complete-hierarchy invariant.
            // If every group is empty, buildHierarchy drops the whole section.
            if ($columns === []) {
                continue;
            }

            $rows[] = new MigrationRow(
                title: $row->title ?? '',
                extraClass: $row->extraClass ?? '',
                sort: $rowSort,
                columns: $columns,
                legacyRow: $row,
            );

            $rowSort++;
        }

        return $rows;
    }
}
