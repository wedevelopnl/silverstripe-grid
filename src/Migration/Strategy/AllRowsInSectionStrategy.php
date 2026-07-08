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
     * @param non-empty-string      $defaultViewport
     * @param array<string, string> $viewportKeyMap  Old viewport key → new key (e.g. 'MD' → 'md')
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
        // Build columns once per group and drop groups that produce none (a
        // delimiter with no following content): an empty Row with no Column
        // violates the complete-hierarchy invariant, and a dropped row must not
        // donate section-level fields like customSectionClass either.
        $survivingGroups = [];
        $columnsPerGroup = [];
        foreach ($this->grouper->group($elements) as $group) {
            $columns = $this->buildColumns($group['elements']);
            if ($columns === []) {
                continue;
            }

            $survivingGroups[] = $group;
            $columnsPerGroup[] = $columns;
        }

        // The two arrays grow in lockstep; checking both lets PHPStan narrow
        // each to non-empty-list for buildRows.
        if ($survivingGroups === [] || $columnsPerGroup === []) {
            return [];
        }

        return [
            new MigrationSection(
                title: '',
                zone: $zone,
                extraClass: $this->resolveSectionExtraClass($survivingGroups),
                sort: 1,
                rows: $this->buildRows($survivingGroups, $columnsPerGroup),
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
     * @param non-empty-list<array{row: ?LegacyElement, rowData: ?LegacyRowData, elements: list<LegacyElement>}> $groups
     *     Groups that survived the empty-row drop in buildHierarchy
     * @param non-empty-list<non-empty-list<MigrationColumn>> $columnsPerGroup Pre-built columns, index-aligned with $groups
     * @return non-empty-list<MigrationRow>
     */
    private function buildRows(array $groups, array $columnsPerGroup): array
    {
        $rows = [];

        foreach ($groups as $index => $group) {
            $row = $group['row'];

            $rows[] = new MigrationRow(
                title: $row !== null ? $row->title : '',
                extraClass: $row !== null ? $row->extraClass : '',
                sort: $index + 1,
                columns: $columnsPerGroup[$index],
            );
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
