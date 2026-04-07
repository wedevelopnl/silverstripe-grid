<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;

/**
 * Reads legacy elemental data from old database tables using raw SQL.
 *
 * The old dnadesign/silverstripe-elemental tables (BaseElement, ElementRow,
 * ElementContent, ElementalArea) remain in the database after the module is
 * removed. This reader extracts data from those tables without requiring any
 * ORM classes for the old schema.
 */
final class LegacyDataReader
{
    use Extensible;

    private const string ROW_CLASS_NAME = 'WeDevelop\\ElementalGrid\\Models\\ElementRow';

    private const array VIEWPORT_KEYS = ['XS', 'SM', 'MD', 'LG', 'XL'];

    private const array MEDIA_FIELDS = [
        'HTML',
        'ContentColumns',
        'ContentVerticalAlign',
        'ExtraColumnGap',
        'MediaType',
        'MediaCaption',
        'MediaRatio',
        'MediaPosition',
        'MediaImageID',
        'MediaVideoFullURL',
        'MediaVideoProvider',
        'MediaVideoHasOverlay',
        'MediaVideoCustomThumbnailID',
        'MediaVideoEmbeddedName',
        'MediaVideoEmbeddedURL',
        'MediaVideoEmbeddedDescription',
        'MediaVideoEmbeddedThumbnail',
        'MediaVideoEmbeddedCreated',
    ];

    /**
     * Find pages that have UseElementalGrid enabled and a valid ElementalArea.
     *
     * The columns may live on SiteTree or Page depending on how the old
     * extension was applied. We LEFT JOIN both and COALESCE to handle either.
     *
     * @param list<int>|null $pageIds Optional filter to restrict to specific pages
     * @return list<array{pageId: int, areaId: int}>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        $siteTreeTable = $this->stageTable('SiteTree', $stage);
        $pageTable = $this->stageTable('Page', $stage);
        $hasPageTable = $this->tableHasColumn($pageTable, 'ElementalAreaID');

        if ($hasPageTable) {
            $sql = <<<SQL
                SELECT
                    s.ID AS pageId,
                    COALESCE(p.ElementalAreaID, s.ElementalAreaID) AS areaId
                FROM "{$siteTreeTable}" s
                LEFT JOIN "{$pageTable}" p ON s.ID = p.ID
                WHERE COALESCE(p.UseElementalGrid, s.UseElementalGrid) = 1
                  AND COALESCE(p.ElementalAreaID, s.ElementalAreaID) > 0
                SQL;
        } else {
            $sql = <<<SQL
                SELECT
                    s.ID AS pageId,
                    s.ElementalAreaID AS areaId
                FROM "{$siteTreeTable}" s
                WHERE s.UseElementalGrid = 1
                  AND s.ElementalAreaID > 0
                SQL;
        }

        $params = [];

        if ($pageIds !== null && $pageIds !== []) {
            $placeholders = \implode(', ', \array_fill(0, \count($pageIds), '?'));
            $sql .= " AND s.ID IN ({$placeholders})";
            $params = $pageIds;
        }

        $sql .= ' ORDER BY s.ID ASC';

        $result = DB::prepared_query($sql, $params);
        $pages = [];

        foreach ($result as $row) {
            /** @var array<string, int|string> $row */
            $pages[] = [
                'pageId' => (int) $row['pageId'],
                'areaId' => (int) $row['areaId'],
            ];
        }

        return $pages;
    }

    /**
     * Load all legacy elements for an ElementalArea, sorted by position.
     *
     * Hydrates each row into a LegacyElement DTO, eagerly fetching associated
     * row data and content media data where applicable. After hydration, the
     * `updateLegacyElements` extension hook is invoked so consuming projects
     * can attach additional data or filter elements.
     *
     * @return list<LegacyElement>
     */
    public function getElementsForArea(int $areaId, string $stage): array
    {
        $table = $this->stageTable('BaseElement', $stage);

        $result = DB::prepared_query(
            "SELECT * FROM \"{$table}\" WHERE \"ParentID\" = ? ORDER BY \"Sort\" ASC",
            [$areaId],
        );

        $elements = [];

        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            $elementId = (int) $row['ID'];
            $className = (string) ($row['ClassName'] ?? '');
            $isRow = $className === self::ROW_CLASS_NAME;

            $rowData = $isRow ? $this->getRowData($elementId, $stage) : null;

            $mediaData = $this->getContentMediaData($elementId, $stage);

            $elements[] = new LegacyElement(
                id: $elementId,
                className: $className,
                title: (string) ($row['Title'] ?? ''),
                showTitle: (bool) ($row['ShowTitle'] ?? false),
                titleTag: (string) ($row['TitleTag'] ?? ''),
                titleClass: (string) ($row['TitleClass'] ?? ''),
                sort: (int) ($row['Sort'] ?? 0),
                extraClass: (string) ($row['ExtraClass'] ?? ''),
                isRow: $isRow,
                sizeFields: $this->extractViewportFields($row, 'Size'),
                offsetFields: $this->extractViewportFields($row, 'Offset'),
                visibilityFields: $this->extractVisibilityFields($row),
                rowData: $rowData,
                mediaData: $mediaData,
            );
        }

        $this->extend('updateLegacyElements', $elements, $areaId, $stage);

        /** @var list<LegacyElement> $elements */
        return $elements;
    }

    /**
     * Fetch row-specific data (IsFluid, CustomSectionClass) from the ElementRow table.
     */
    public function getRowData(int $elementId, string $stage): ?LegacyRowData
    {
        $table = $this->stageTable('ElementRow', $stage);

        $result = DB::prepared_query(
            "SELECT * FROM \"{$table}\" WHERE \"ID\" = ?",
            [$elementId],
        );

        if ($result->numRecords() === 0) {
            return null;
        }

        /** @var array<string, int|string|null> $row */
        $row = $result->record();

        return new LegacyRowData(
            isFluid: (bool) ($row['IsFluid'] ?? false),
            customSectionClass: (string) ($row['CustomSectionClass'] ?? ''),
        );
    }

    /**
     * Fetch content media extension fields from the ElementContent table.
     */
    public function getContentMediaData(int $elementId, string $stage): ?LegacyMediaData
    {
        $table = $this->stageTable('ElementContent', $stage);

        $result = DB::prepared_query(
            "SELECT * FROM \"{$table}\" WHERE \"ID\" = ?",
            [$elementId],
        );

        if ($result->numRecords() === 0) {
            return null;
        }

        /** @var array<string, int|string|null> $row */
        $row = $result->record();
        $fields = [];
        foreach (self::MEDIA_FIELDS as $field) {
            if (\array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        return new LegacyMediaData(fields: $fields);
    }

    /**
     * Extract per-viewport integer fields (Size or Offset) from a database row.
     *
     * @param array<string, int|string|null> $row
     * @return array<string, int>
     */
    private function extractViewportFields(array $row, string $prefix): array
    {
        $fields = [];

        foreach (self::VIEWPORT_KEYS as $viewport) {
            $column = $prefix . $viewport;
            $fields[$viewport] = (int) ($row[$column] ?? 0);
        }

        return $fields;
    }

    /**
     * Extract per-viewport visibility fields from a database row.
     *
     * @param array<string, int|string|null> $row
     * @return array<string, ?string>
     */
    private function extractVisibilityFields(array $row): array
    {
        $fields = [];

        foreach (self::VIEWPORT_KEYS as $viewport) {
            $column = 'Visibility' . $viewport;
            $value = $row[$column] ?? null;
            $fields[$viewport] = $value === null || $value === '' ? null : (string) $value;
        }

        return $fields;
    }

    /**
     * Check whether a table exists and contains a specific column.
     *
     * Used to detect whether the old extension was applied to SiteTree or Page,
     * since both are valid targets for the UseElementalGrid/ElementalAreaID columns.
     */
    private function tableHasColumn(string $table, string $column): bool
    {
        $tables = DB::table_list();

        // table_list() returns lowercase table names as keys
        if (!\array_key_exists(\strtolower($table), $tables)) {
            return false;
        }

        $columns = DB::field_list($table);

        return \array_key_exists($column, $columns);
    }

    /**
     * Resolve the table name for a given stage.
     *
     * Draft reads from the base table, live reads from the _Live suffixed table.
     */
    private function stageTable(string $baseTable, string $stage): string
    {
        return \strtolower($stage) === 'live' ? $baseTable . '_Live' : $baseTable;
    }
}
