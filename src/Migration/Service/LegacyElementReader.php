<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use InvalidArgumentException;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Reads and hydrates legacy elemental elements from old database tables using raw SQL.
 *
 * The old dnadesign/silverstripe-elemental tables (BaseElement, ElementRow,
 * ElementContent) remain in the database after the module is removed. This
 * reader extracts element data from those tables without requiring any ORM
 * classes for the old schema.
 *
 * Companion reads (ElementRow / ElementContent and their _Localised overlays)
 * are batched: each area read issues a single `IN (…)` query per companion
 * table instead of one query per element, then hydrates from the prefetched
 * maps. The single-ID accessors ({@see getRowData()}, {@see getContentMediaData()},
 * {@see getContentMediaDataInLocale()}) remain available for direct lookups but
 * are not used on the batch hydration path.
 */
final class LegacyElementReader
{
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
     * Load all legacy elements for an ElementalArea, sorted by position.
     *
     * Hydrates each row into a LegacyElement DTO. Associated row data and
     * content media data are batch-prefetched (one query per companion table)
     * and read from the in-memory maps during hydration.
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

        /** @var list<array<string, int|string|null>> $rows */
        $rows = [];
        /** @var list<positive-int> $rowDelimiterIds */
        $rowDelimiterIds = [];
        /** @var list<positive-int> $contentIds */
        $contentIds = [];

        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            /** @var positive-int $elementId */
            $elementId = (int) $row['ID'];
            $rows[] = $row;
            $contentIds[] = $elementId;
            if ((string) ($row['ClassName'] ?? '') === self::ROW_CLASS_NAME) {
                $rowDelimiterIds[] = $elementId;
            }
        }

        $rowDataMap = $this->prefetchRowData($rowDelimiterIds, $stage);
        $contentRowMap = $this->prefetchContentRows($contentIds, $stage);

        $elements = [];
        foreach ($rows as $row) {
            /** @var positive-int $elementId */
            $elementId = (int) $row['ID'];
            $className = (string) ($row['ClassName'] ?? '');
            $isRow = $className === self::ROW_CLASS_NAME;

            $rowData = $isRow ? ($rowDataMap[$elementId] ?? null) : null;
            $mediaData = isset($contentRowMap[$elementId])
                ? $this->projectMediaFields($contentRowMap[$elementId])
                : null;

            $elements[] = $this->buildElement($row, $elementId, $className, $isRow, $rowData, $mediaData);
        }

        return $elements;
    }

    /**
     * Locale-aware variant of {@see getElementsForArea()}.
     *
     * Companion reads (and the per-locale _Localised overlays) are batched the
     * same way as the base read; the FieldLocalised model overlays
     * BaseElement_Localised onto each base row before classification, mirroring
     * the per-element overlay precedence exactly.
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

        /** @var list<array<string, int|string|null>> $rows */
        $rows = [];
        /** @var list<positive-int> $allIds */
        $allIds = [];

        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            /** @var positive-int $elementId */
            $elementId = (int) $row['ID'];
            $rows[] = $row;
            $allIds[] = $elementId;
        }

        // Overlay BaseElement_Localised first so classification (isRow) and
        // every downstream read see the same values the per-element path did.
        $baseLocalisedMap = $overlayLocale !== null
            ? $this->prefetchLocalised('BaseElement', $allIds, $overlayLocale, $stage)
            : [];

        /** @var list<array{array<string, int|string|null>, positive-int, string, bool}> $prepared */
        $prepared = [];
        /** @var list<positive-int> $rowDelimiterIds */
        $rowDelimiterIds = [];
        /** @var list<positive-int> $contentIds */
        $contentIds = [];

        foreach ($rows as $row) {
            /** @var positive-int $elementId */
            $elementId = (int) $row['ID'];
            if ($overlayLocale !== null && isset($baseLocalisedMap[$elementId])) {
                $row = $this->applyLocalisedOverlay($row, $baseLocalisedMap[$elementId]);
            }
            $className = (string) ($row['ClassName'] ?? '');
            $isRow = $className === self::ROW_CLASS_NAME;
            if ($isRow) {
                $rowDelimiterIds[] = $elementId;
            }
            $contentIds[] = $elementId;
            $prepared[] = [$row, $elementId, $className, $isRow];
        }

        $rowDataMap = $this->prefetchRowData($rowDelimiterIds, $stage);
        $contentRowMap = $this->prefetchContentRows($contentIds, $stage);
        $contentLocalisedMap = $overlayLocale !== null
            ? $this->prefetchLocalised('ElementContent', $contentIds, $overlayLocale, $stage)
            : [];

        $elements = [];
        foreach ($prepared as [$row, $elementId, $className, $isRow]) {
            $rowData = $isRow ? ($rowDataMap[$elementId] ?? null) : null;

            if (isset($contentRowMap[$elementId])) {
                $contentRow = $contentRowMap[$elementId];
                if ($overlayLocale !== null && isset($contentLocalisedMap[$elementId])) {
                    $contentRow = $this->applyLocalisedOverlay($contentRow, $contentLocalisedMap[$elementId]);
                }
                $mediaData = $this->projectMediaFields($contentRow);
            } else {
                $mediaData = null;
            }

            $elements[] = $this->buildElement($row, $elementId, $className, $isRow, $rowData, $mediaData);
        }

        return $elements;
    }

    /**
     * Fetch row-specific data (IsFluid, CustomSectionClass) from the ElementRow table.
     *
     * Single-ID accessor retained for direct lookups; the batch hydration path
     * uses {@see prefetchRowData()} instead.
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
     *
     * Single-ID accessor retained for direct lookups; the batch hydration path
     * uses {@see prefetchContentRows()} instead.
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

        return $this->projectMediaFields($row);
    }

    /**
     * Locale-aware variant of {@see getContentMediaData()}: overlays
     * ElementContent_Localised values for the locale onto the base media row.
     *
     * Single-ID accessor retained for direct lookups; the batch hydration path
     * overlays from {@see prefetchLocalised()} instead.
     *
     * @param non-empty-string $localeCode
     */
    public function getContentMediaDataInLocale(int $elementId, string $stage, string $localeCode): ?LegacyMediaData
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

        return $this->projectMediaFields($row);
    }

    /**
     * Batch-load row data for the given element IDs from the ElementRow table.
     *
     * Issues a single `IN (…)` query and keys the result by element ID. Returns
     * an empty map (and skips the query) when no IDs are supplied — an empty
     * `IN ()` clause is invalid SQL.
     *
     * @param list<positive-int> $ids
     * @return array<int, LegacyRowData>
     */
    private function prefetchRowData(array $ids, string $stage): array
    {
        if ($ids === []) {
            return [];
        }

        $table = $this->stageTable('ElementRow', $stage);
        $placeholders = \implode(', ', \array_fill(0, \count($ids), '?'));

        $result = DB::prepared_query(
            "SELECT * FROM \"{$table}\" WHERE \"ID\" IN ({$placeholders})",
            $ids,
        );

        $map = [];
        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            $id = (int) $row['ID'];
            $map[$id] = new LegacyRowData(
                customSectionClass: (string) ($row['CustomSectionClass'] ?? ''),
            );
        }

        return $map;
    }

    /**
     * Batch-load raw ElementContent rows for the given element IDs.
     *
     * Issues a single `IN (…)` query and keys the result by element ID. Returns
     * an empty map (and skips the query) when no IDs are supplied.
     *
     * @param list<positive-int> $ids
     * @return array<int, array<string, int|string|null>>
     */
    private function prefetchContentRows(array $ids, string $stage): array
    {
        if ($ids === []) {
            return [];
        }

        $table = $this->stageTable('ElementContent', $stage);
        $placeholders = \implode(', ', \array_fill(0, \count($ids), '?'));

        $result = DB::prepared_query(
            "SELECT * FROM \"{$table}\" WHERE \"ID\" IN ({$placeholders})",
            $ids,
        );

        $map = [];
        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            $id = (int) $row['ID'];
            $map[$id] = $row;
        }

        return $map;
    }

    /**
     * Batch-load <baseTable>_Localised companion rows for a locale, keyed by RecordID.
     *
     * Mirrors {@see overlayLocalised()} precedence: when the localised table is
     * absent, or no IDs are supplied, an empty map is returned and no overlay is
     * applied. The first row encountered per RecordID wins, matching the
     * single-record semantics of the per-element read.
     *
     * @param list<positive-int> $ids
     * @param non-empty-string $localeCode
     * @return array<int, array<string, int|string|null>>
     */
    private function prefetchLocalised(string $baseTable, array $ids, string $localeCode, string $stage): array
    {
        if ($ids === []) {
            return [];
        }

        $localisedTable = $this->localisedTable($baseTable, $stage);
        if (!\array_key_exists(\strtolower($localisedTable), DB::table_list())) {
            return [];
        }

        $placeholders = \implode(', ', \array_fill(0, \count($ids), '?'));
        /** @var list<int|string> $params */
        $params = $ids;
        $params[] = $localeCode;

        $result = DB::prepared_query(
            "SELECT * FROM \"{$localisedTable}\" WHERE \"RecordID\" IN ({$placeholders}) AND \"Locale\" = ?",
            $params,
        );

        $map = [];
        foreach ($result as $row) {
            /** @var array<string, int|string|null> $row */
            $recordId = (int) $row['RecordID'];
            if (!isset($map[$recordId])) {
                $map[$recordId] = $row;
            }
        }

        return $map;
    }

    /**
     * Build a LegacyElement from a (possibly overlaid) BaseElement row plus its
     * prefetched companion data.
     *
     * @param array<string, int|string|null> $row
     * @param positive-int $elementId
     */
    private function buildElement(
        array $row,
        int $elementId,
        string $className,
        bool $isRow,
        ?LegacyRowData $rowData,
        ?LegacyMediaData $mediaData,
    ): LegacyElement {
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
     * Project the MEDIA_FIELDS subset of a content row into a LegacyMediaData DTO.
     *
     * @param array<string, int|string|null> $row
     */
    private function projectMediaFields(array $row): LegacyMediaData
    {
        $fields = [];
        foreach (self::MEDIA_FIELDS as $field) {
            if (\array_key_exists($field, $row)) {
                $fields[$field] = $row[$field];
            }
        }

        return new LegacyMediaData(fields: $fields);
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

        return $this->applyLocalisedOverlay($row, $localised);
    }

    /**
     * Overlay populated localised values onto a base row.
     *
     * Skips Fluent bookkeeping columns (ID/RecordID/Locale) and only overlays
     * non-null localised values, preserving the per-element overlay precedence.
     *
     * @param array<string, int|string|null> $row
     * @param array<string, int|string|null> $localised
     * @return array<string, int|string|null>
     */
    private function applyLocalisedOverlay(array $row, array $localised): array
    {
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
     * Resolve a <baseTable>_Localised companion table name for a stage.
     *
     * Delegates to {@see stageTable()} so stage validation is not bypassed and
     * the resulting names stay exactly `<base>_Localised` (draft) / `<base>_Localised_Live` (live).
     */
    private function localisedTable(string $baseTable, string $stage): string
    {
        return $this->stageTable($baseTable . '_Localised', $stage);
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
     * Resolve the table name for a given stage.
     *
     * Draft reads from the base table, live reads from the _Live suffixed table.
     *
     * Duplicated from {@see LegacyPageDiscovery::stageTable()} deliberately: it
     * is eight lines of pure stage validation, and duplicating it keeps the two
     * readers fully decoupled (no shared base class or injected collaborator
     * just to share a trivial helper).
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
