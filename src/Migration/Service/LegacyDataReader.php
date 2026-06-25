<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use InvalidArgumentException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Reads legacy elemental data from old database tables using raw SQL.
 *
 * The old dnadesign/silverstripe-elemental tables (BaseElement, ElementRow,
 * ElementContent, ElementalArea) remain in the database after the module is
 * removed. This reader extracts data from those tables without requiring any
 * ORM classes for the old schema.
 *
 * Supports two migration sources:
 * - WeDevelop ElementalGrid: pages have both UseElementalGrid and ElementalAreaID columns
 * - Plain dnadesign/silverstripe-elemental: pages have only ElementalAreaID (no UseElementalGrid)
 *
 * When UseElementalGrid is absent, all pages with ElementalAreaID > 0 are eligible.
 */
final class LegacyDataReader implements LegacyElementSource
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
     * @return list<array{pageId: int, areaId: int, pageClassName: class-string}>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        // The old elemental extension could be applied to any SiteTree subclass
        // (Page, HomePage, BlogPage, etc.). Discover which tables have the column
        // using the class manifest, then query each.
        $baseTables = $this->findPageTablesWithColumn('ElementalAreaID');

        if ($baseTables === []) {
            return [];
        }

        // ClassName always lives on the SiteTree base table. JOIN with it
        // to resolve the concrete page class for polymorphic ParentClass.
        $siteTreeTable = $this->stageTable('SiteTree', $stage);

        $pages = [];
        /** @var array<int, true> $seen */
        $seen = [];

        foreach ($baseTables as $baseTable) {
            $table = $this->stageTable($baseTable, $stage);
            $isSiteTreeTable = ($table === $siteTreeTable);
            $hasUseElementalGrid = $this->tableHasColumn($baseTable, 'UseElementalGrid');

            if ($isSiteTreeTable) {
                $sql = <<<SQL
                    SELECT "ID" AS pageId,
                           "ElementalAreaID" AS areaId,
                           "ClassName" AS pageClassName
                    FROM "{$table}"
                    WHERE "ElementalAreaID" > 0
                    SQL;

                if ($hasUseElementalGrid) {
                    $sql .= ' AND "UseElementalGrid" = 1';
                }
            } else {
                $sql = <<<SQL
                    SELECT "{$table}"."ID" AS pageId,
                           "{$table}"."ElementalAreaID" AS areaId,
                           "_st"."ClassName" AS pageClassName
                    FROM "{$table}"
                    INNER JOIN "{$siteTreeTable}" AS "_st" ON "_st"."ID" = "{$table}"."ID"
                    WHERE "{$table}"."ElementalAreaID" > 0
                    SQL;

                if ($hasUseElementalGrid) {
                    $sql .= \sprintf(' AND "%s"."UseElementalGrid" = 1', $table);
                }
            }

            $params = [];

            if ($pageIds !== null && $pageIds !== []) {
                $placeholders = \implode(', ', \array_fill(0, \count($pageIds), '?'));
                $idColumn = $isSiteTreeTable ? '"ID"' : \sprintf('"%s"."ID"', $table);
                $sql .= " AND {$idColumn} IN ({$placeholders})";
                $params = $pageIds;
            }

            $orderColumn = $isSiteTreeTable ? '"ID"' : \sprintf('"%s"."ID"', $table);
            $sql .= " ORDER BY {$orderColumn} ASC";

            $result = DB::prepared_query($sql, $params);

            foreach ($result as $row) {
                /** @var array<string, int|string> $row */
                $id = (int) $row['pageId'];
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                /** @var class-string $pageClassName */
                $pageClassName = (string) $row['pageClassName'];
                $pages[] = [
                    'pageId' => $id,
                    'areaId' => (int) $row['areaId'],
                    'pageClassName' => $pageClassName,
                ];
            }
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
            $elements[] = $this->hydrateElement($row, $stage, null);
        }

        $this->extend('updateLegacyElements', $elements, $areaId, $stage);

        /** @var list<LegacyElement> $elements */
        return $elements;
    }

    /**
     * Locale-aware variant of {@see getElementsForArea()}.
     *
     * @param non-empty-string $localeCode Fluent locale code (e.g. 'nl_NL')
     * @param positive-int $localeId Fluent Locale record ID (used by the Isolated model)
     * @return list<LegacyElement>
     */
    public function getElementsForAreaInLocale(
        int $areaId,
        string $stage,
        LegacyLocalisationModel $model,
        string $localeCode,
        int $localeId,
    ): array {
        if ($model === LegacyLocalisationModel::None) {
            return $this->getElementsForArea($areaId, $stage);
        }

        $table = $this->stageTable('BaseElement', $stage);

        if ($model === LegacyLocalisationModel::Isolated) {
            $result = DB::prepared_query(
                "SELECT * FROM \"{$table}\" WHERE \"ParentID\" = ? AND \"LocaleID\" = ? ORDER BY \"Sort\" ASC",
                [$areaId, $localeId],
            );
        } else {
            // FieldLocalised: shared base rows, overlaid per locale below.
            $result = DB::prepared_query(
                "SELECT * FROM \"{$table}\" WHERE \"ParentID\" = ? ORDER BY \"Sort\" ASC",
                [$areaId],
            );
        }

        $overlayLocale = $model === LegacyLocalisationModel::FieldLocalised ? $localeCode : null;

        $elements = [];
        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            if ($overlayLocale !== null) {
                /** @var positive-int $elementId */
                $elementId = (int) $row['ID'];
                $row = $this->overlayLocalised($row, 'BaseElement', $elementId, $overlayLocale, $stage);
            }
            $elements[] = $this->hydrateElement($row, $stage, $overlayLocale);
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
     * Find pages that have UseElementalGrid explicitly disabled (= 0).
     *
     * Used during migration to preserve the toggle state: pages that opted out
     * of the grid in the old module should keep UseGrid = 0 in the new module.
     *
     * Returns an empty array when the UseElementalGrid column does not exist
     * (plain dnadesign/silverstripe-elemental without the WeDevelop extension).
     *
     * @return list<array{pageId: int}>
     */
    public function getPagesWithGridDisabled(string $stage): array
    {
        $baseTables = $this->findPageTablesWithColumn('UseElementalGrid');

        if ($baseTables === []) {
            return [];
        }

        $pages = [];
        /** @var array<int, true> $seen */
        $seen = [];

        foreach ($baseTables as $baseTable) {
            $table = $this->stageTable($baseTable, $stage);

            $result = DB::query(\sprintf(
                'SELECT "ID" AS pageId FROM "%s" WHERE "UseElementalGrid" = 0',
                $table,
            ));

            foreach ($result as $row) {
                /** @var array{pageId: int|string} $row */
                $pageId = (int) $row['pageId'];
                if (isset($seen[$pageId])) {
                    continue;
                }
                $seen[$pageId] = true;
                $pages[] = ['pageId' => $pageId];
            }
        }

        return $pages;
    }

    /**
     * Build a LegacyElement from a BaseElement row. When $overlayLocale is set,
     * content media fields are overlaid from ElementContent_Localised for that locale.
     *
     * @param array<string, int|string|null> $row
     * @param non-empty-string|null $overlayLocale
     */
    private function hydrateElement(array $row, string $stage, ?string $overlayLocale): LegacyElement
    {
        /** @var positive-int $elementId */
        $elementId = (int) $row['ID'];
        $className = (string) ($row['ClassName'] ?? '');
        $isRow = $className === self::ROW_CLASS_NAME;

        $rowData = $isRow ? $this->getRowData($elementId, $stage) : null;
        $mediaData = $overlayLocale !== null
            ? $this->getContentMediaDataInLocale($elementId, $stage, $overlayLocale)
            : $this->getContentMediaData($elementId, $stage);

        return new LegacyElement(
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

    /**
     * Overlay non-null values from a <baseTable>_Localised companion onto a base row.
     *
     * @param array<string, int|string|null> $row
     * @param non-empty-string $localeCode
     * @return array<string, int|string|null>
     */
    private function overlayLocalised(array $row, string $baseTable, int $recordId, string $localeCode, string $stage): array
    {
        $localisedTable = $this->localisedTable($baseTable, $stage);
        if (!\array_key_exists(\strtolower($localisedTable), DB::table_list())) {
            return $row;
        }

        $result = DB::prepared_query(
            "SELECT * FROM \"{$localisedTable}\" WHERE \"RecordID\" = ? AND \"Locale\" = ?",
            [$recordId, $localeCode],
        );
        if ($result->numRecords() === 0) {
            return $row;
        }

        /** @var array<string, int|string|null> $localised */
        $localised = $result->record();
        foreach ($localised as $column => $value) {
            // Skip Fluent bookkeeping columns; overlay only populated localised values.
            if (\in_array($column, ['ID', 'RecordID', 'Locale'], true)) {
                continue;
            }
            if ($value !== null) {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    /**
     * Locale-aware variant of {@see getContentMediaData()}: overlays
     * ElementContent_Localised values for the locale onto the base media row.
     *
     * @param non-empty-string $localeCode
     */
    private function getContentMediaDataInLocale(int $elementId, string $stage, string $localeCode): ?LegacyMediaData
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
        $row = $this->overlayLocalised($row, 'ElementContent', $elementId, $localeCode, $stage);

        $fields = [];
        foreach (self::MEDIA_FIELDS as $field) {
            if (\array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        return new LegacyMediaData(fields: $fields);
    }

    /**
     * Resolve a <baseTable>_Localised companion table name for a stage.
     */
    private function localisedTable(string $baseTable, string $stage): string
    {
        return \strtolower($stage) === 'live'
            ? $baseTable . '_Localised_Live'
            : $baseTable . '_Localised';
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
     * Find page tables that have a given column.
     *
     * Uses the class manifest to discover all SiteTree subclass table names,
     * then checks each for the column. Only scans page tables, not the entire database.
     *
     * @return list<string> Base table names (without stage suffix)
     */
    private function findPageTablesWithColumn(string $column): array
    {
        $schema = DataObject::getSchema();
        $result = [];
        $checked = [];

        foreach (ClassInfo::subclassesFor(SiteTree::class, true) as $class) {
            $table = $schema->tableName($class);
            if ($table === null || isset($checked[$table])) {
                continue;
            }
            $checked[$table] = true;

            if ($this->tableHasColumn($table, $column)) {
                $result[] = $table;
            }
        }

        return $result;
    }

    /**
     * Resolve the table name for a given stage.
     *
     * Draft reads from the base table, live reads from the _Live suffixed table.
     */
    private function stageTable(string $baseTable, string $stage): string
    {
        $normalized = \strtolower($stage);
        if ($normalized !== 'draft' && $normalized !== 'live') {
            throw new InvalidArgumentException(\sprintf('Invalid stage "%s", expected "draft" or "live"', $stage));
        }

        return $normalized === 'live' ? $baseTable . '_Live' : $baseTable;
    }
}
