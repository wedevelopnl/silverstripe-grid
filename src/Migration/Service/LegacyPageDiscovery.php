<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use InvalidArgumentException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Discovers legacy elemental pages from old database tables using raw SQL.
 *
 * The old dnadesign/silverstripe-elemental tables (BaseElement, ElementRow,
 * ElementContent, ElementalArea) remain in the database after the module is
 * removed. This discovery service locates eligible pages and grid-toggle state
 * from those page tables without requiring any ORM classes for the old schema.
 *
 * Supports two migration sources:
 * - WeDevelop ElementalGrid: pages have both UseElementalGrid and ElementalAreaID columns
 * - Plain dnadesign/silverstripe-elemental: pages have only ElementalAreaID (no UseElementalGrid)
 *
 * When UseElementalGrid is absent, all pages with ElementalAreaID > 0 are eligible.
 */
final class LegacyPageDiscovery
{
    /**
     * Instance-level cache for DB::table_list(). Populated on first call to
     * tableHasColumn(); null means not yet fetched.
     *
     * @var array<string, mixed>|null
     */
    private ?array $tableListCache = null;

    /**
     * Instance-level cache for DB::field_list(), keyed by table name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $fieldListCache = [];

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
     * Check whether a table exists and contains a specific column.
     *
     * Used to detect whether the old extension was applied to SiteTree or Page,
     * since both are valid targets for the UseElementalGrid/ElementalAreaID columns.
     *
     * The cache lives on the per-migration discovery instance; the discovery is
     * constructed fresh per task run so there is no cross-run staleness — the
     * migration never adds tables or columns mid-run.
     */
    private function tableHasColumn(string $table, string $column): bool
    {
        if ($this->tableListCache === null) {
            /** @var array<string, mixed> $tableList */
            $tableList = DB::table_list();
            $this->tableListCache = $tableList;
        }

        // table_list() returns lowercase table names as keys
        if (!\array_key_exists(\strtolower($table), $this->tableListCache)) {
            return false;
        }

        if (!isset($this->fieldListCache[$table])) {
            /** @var array<string, mixed> $columns */
            $columns = DB::field_list($table);
            $this->fieldListCache[$table] = $columns;
        }

        return \array_key_exists($column, $this->fieldListCache[$table]);
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
