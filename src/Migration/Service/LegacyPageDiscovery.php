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
 * The old dnadesign/silverstripe-elemental tables (Element, ElementRow,
 * ElementContent, ElementalArea) remain in the database after the module is
 * removed. This discovery service locates eligible pages and grid-toggle state
 * from those page tables without requiring any ORM classes for the old schema.
 *
 * Supports two migration sources:
 * - WeDevelop ElementalGrid: pages have both UseElementalGrid and ElementalAreaID columns
 * - Plain dnadesign/silverstripe-elemental: pages have only ElementalAreaID (no UseElementalGrid)
 *
 * When UseElementalGrid is absent, all pages with ElementalAreaID > 0 are eligible.
 *
 * @phpstan-import-type EligiblePage from LegacyElementSource
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
     * Every page table is read as one area shared by all locales, so a page's
     * live elements come from the same area as its draft ones.
     *
     * @param list<int>|null $pageIds Optional filter to restrict to specific pages
     * @return list<EligiblePage>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        // The old elemental extension could be applied to any SiteTree subclass
        // (Page, HomePage, BlogPage, etc.). Discover which tables have the column
        // using the class manifest, then query each.
        $pages = [];
        foreach ($this->findPageTablesWithColumn('ElementalAreaID') as $baseTable) {
            $pages = $this->appendUnseen($pages, $this->sharedAreaPages($baseTable, $stage, $pageIds));
        }

        return $pages;
    }

    /**
     * Eligible pages for one Fluent locale. On a page table whose area is per
     * locale ({@see getPerLocaleAreaTables()}) the first locale in `$chain`
     * with a draft `_Localised` row for the page supplies both stages: that
     * row's area, and the area of its own `_Localised_Live` row, or none. Only
     * the default locale falls back to the base columns, and only without such
     * a row. A page that resolves no draft area is left out. Any other page
     * table shares one area across locales and joins only when
     * `$withSharedAreaTables` is set.
     *
     * @param non-empty-list<non-empty-string> $chain The locale, then its fallbacks in order
     * @param list<int>|null $pageIds
     * @return list<EligiblePage>
     */
    public function getEligiblePagesInLocale(
        array $chain,
        bool $isDefault,
        bool $withSharedAreaTables,
        ?array $pageIds = null,
    ): array {
        $pages = [];
        foreach ($this->findPageTablesWithColumn('ElementalAreaID') as $baseTable) {
            if ($this->isPerLocaleAreaTable($baseTable)) {
                $pages = $this->appendUnseen($pages, $this->perLocaleAreaPages($baseTable, $chain, $isDefault, $pageIds));
            } elseif ($withSharedAreaTables) {
                $pages = $this->appendUnseen($pages, $this->sharedAreaPages($baseTable, 'draft', $pageIds));
            }
        }

        return $pages;
    }

    /**
     * Page tables whose ElementalAreaID Fluent localised: `<Table>_Localised`
     * carries the column, so each locale points at its own area.
     *
     * @return list<string>
     */
    public function getPerLocaleAreaTables(): array
    {
        return \array_values(\array_filter(
            $this->findPageTablesWithColumn('ElementalAreaID'),
            $this->isPerLocaleAreaTable(...),
        ));
    }

    /**
     * Page tables whose per-locale ElementalAreaID dev/build set aside, each
     * with the companions (`<Table>_Localised`, `<Table>_Localised_Live`) that
     * lack the column while their `_obsolete_` copy carries it.
     *
     * @return array<string, non-empty-list<string>> page table → companions still set aside
     */
    public function getSetAsideAreaTables(): array
    {
        $setAside = [];
        foreach ($this->findPageTables() as $table) {
            $companions = \array_values(\array_filter(
                [$table . '_Localised', $table . '_Localised_Live'],
                fn (string $companion): bool => $this->tableHasColumn('_obsolete_' . $companion, 'ElementalAreaID')
                    && !$this->tableHasColumn($companion, 'ElementalAreaID'),
            ));
            if ($companions !== []) {
                $setAside[$table] = $companions;
            }
        }

        return $setAside;
    }

    private function isPerLocaleAreaTable(string $baseTable): bool
    {
        return $this->tableHasColumn($baseTable . '_Localised', 'ElementalAreaID');
    }

    /**
     * @param list<int>|null $pageIds
     * @return list<EligiblePage>
     */
    private function sharedAreaPages(string $baseTable, string $stage, ?array $pageIds): array
    {
        // ClassName always lives on the SiteTree base table. JOIN with it
        // to resolve the concrete page class for polymorphic ParentClass.
        $siteTreeTable = $this->stageTable('SiteTree', $stage);
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

        $idColumn = $isSiteTreeTable ? '"ID"' : \sprintf('"%s"."ID"', $table);
        [$pageCondition, $params] = $this->pageIdCondition($idColumn, $pageIds);
        if ($pageCondition !== '') {
            $sql .= " AND {$pageCondition}";
        }

        $sql .= " ORDER BY {$idColumn} ASC";

        $pages = [];
        foreach (DB::prepared_query($sql, $params) as $row) {
            /** @var array<string, int|string> $row */
            $areaId = (int) $row['areaId'];
            /** @var class-string $pageClassName */
            $pageClassName = (string) $row['pageClassName'];
            $pages[] = [
                'pageId' => (int) $row['pageId'],
                'areaId' => $areaId,
                'liveAreaId' => $areaId,
                'pageClassName' => $pageClassName,
            ];
        }

        return $pages;
    }

    /**
     * Three queries per table whatever the number of pages: the draft page
     * rows (with their base columns), then the `_Localised` rows of every
     * locale in the chain, per stage.
     *
     * @param non-empty-list<non-empty-string> $chain
     * @param list<int>|null $pageIds
     * @return list<EligiblePage>
     */
    private function perLocaleAreaPages(string $baseTable, array $chain, bool $isDefault, ?array $pageIds): array
    {
        $conditions = [];
        if ($this->tableHasColumn($baseTable, 'UseElementalGrid')) {
            $conditions[] = \sprintf('"%s"."UseElementalGrid" = 1', $baseTable);
        }

        [$pageCondition, $params] = $this->pageIdCondition(\sprintf('"%s"."ID"', $baseTable), $pageIds);
        if ($pageCondition !== '') {
            $conditions[] = $pageCondition;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . \implode(' AND ', $conditions);
        $sql = <<<SQL
            SELECT "{$baseTable}"."ID" AS pageId,
                   "{$baseTable}"."ElementalAreaID" AS areaId,
                   "_live"."ElementalAreaID" AS liveAreaId,
                   "_st"."ClassName" AS pageClassName
            FROM "{$baseTable}"
            INNER JOIN "SiteTree" AS "_st" ON "_st"."ID" = "{$baseTable}"."ID"
            LEFT JOIN "{$baseTable}_Live" AS "_live" ON "_live"."ID" = "{$baseTable}"."ID"
            {$where}
            ORDER BY "{$baseTable}"."ID" ASC
            SQL;

        $draftAreas = $this->localisedAreas($baseTable . '_Localised', $chain, $pageIds);
        $liveAreas = $this->localisedAreas($baseTable . '_Localised_Live', $chain, $pageIds);

        $pages = [];
        foreach (DB::prepared_query($sql, $params) as $row) {
            /** @var array<string, int|string|null> $row */
            $pageId = (int) $row['pageId'];
            $source = $this->sourceLocale($draftAreas[$pageId] ?? [], $chain);
            if ($source !== null) {
                $areaId = $draftAreas[$pageId][$source];
                $liveAreaId = $liveAreas[$pageId][$source] ?? 0;
            } elseif ($isDefault) {
                $areaId = (int) $row['areaId'];
                $liveAreaId = (int) $row['liveAreaId'];
            } else {
                continue;
            }

            if ($areaId <= 0) {
                continue;
            }

            /** @var class-string $pageClassName */
            $pageClassName = (string) $row['pageClassName'];
            $page = [
                'pageId' => $pageId,
                'areaId' => $areaId,
                'liveAreaId' => $liveAreaId,
                'pageClassName' => $pageClassName,
            ];
            if ($source !== null && $source !== $chain[0]) {
                $page['fallback'] = $source;
            }
            $pages[] = $page;
        }

        return $pages;
    }

    /**
     * @param non-empty-list<non-empty-string> $chain
     * @param list<int>|null $pageIds
     * @return array<int, array<string, int>> page ID → locale → area ID
     */
    private function localisedAreas(string $localisedTable, array $chain, ?array $pageIds): array
    {
        if (!$this->tableHasColumn($localisedTable, 'ElementalAreaID')) {
            return [];
        }

        $sql = \sprintf(
            'SELECT "RecordID", "Locale", "ElementalAreaID" FROM "%s" WHERE "Locale" IN (%s)',
            $localisedTable,
            \implode(', ', \array_fill(0, \count($chain), '?')),
        );
        [$pageCondition, $pageParams] = $this->pageIdCondition('"RecordID"', $pageIds);
        if ($pageCondition !== '') {
            $sql .= " AND {$pageCondition}";
        }

        $areas = [];
        foreach (DB::prepared_query($sql, [...$chain, ...$pageParams]) as $row) {
            /** @var array{RecordID: int|string, Locale: string, ElementalAreaID: int|string|null} $row */
            $areas[(int) $row['RecordID']][$row['Locale']] = (int) $row['ElementalAreaID'];
        }

        return $areas;
    }

    /**
     * The optional page filter as a condition on `$column` with its params;
     * an empty condition when `$pageIds` filters nothing.
     *
     * @param list<int>|null $pageIds
     * @return array{string, list<int>}
     */
    private function pageIdCondition(string $column, ?array $pageIds): array
    {
        if ($pageIds === null || $pageIds === []) {
            return ['', []];
        }

        return [\sprintf('%s IN (%s)', $column, \implode(', ', \array_fill(0, \count($pageIds), '?'))), $pageIds];
    }

    /**
     * The first locale in the chain with a draft row, the way Fluent picked a
     * localised field's value. That locale supplies both stages: a translation
     * it never published has no live area, and must not borrow a fallback's.
     *
     * @param array<string, int> $draftAreasByLocale
     * @param non-empty-list<non-empty-string> $chain
     * @return non-empty-string|null
     */
    private function sourceLocale(array $draftAreasByLocale, array $chain): ?string
    {
        foreach ($chain as $locale) {
            if (\array_key_exists($locale, $draftAreasByLocale)) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * A page reachable through more than one page table is taken from the
     * first table that yields it.
     *
     * @param list<EligiblePage> $pages
     * @param list<EligiblePage> $found
     * @return list<EligiblePage>
     */
    private function appendUnseen(array $pages, array $found): array
    {
        $seen = \array_flip(\array_column($pages, 'pageId'));
        foreach ($found as $page) {
            if (!isset($seen[$page['pageId']])) {
                $seen[$page['pageId']] = true;
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /**
     * Every legacy area that more than one eligible draft page uses, with all
     * of those pages. Each page migrates its own copy of such an area.
     *
     * Structural: it ignores any page filter, so a run over some of the pages
     * still sees the pages it leaves out.
     *
     * @return array<int, list<int>> area ID → page IDs, ascending
     */
    public function getSharedAreas(): array
    {
        return $this->sharedAreasOf($this->getEligiblePages('draft'));
    }

    /**
     * {@see getSharedAreas()} for the areas {@see getEligiblePagesInLocale()}
     * resolves: a locale borrowing a page's area from its fallback does not
     * share it, as the page is still its only user in this locale.
     *
     * @param non-empty-list<non-empty-string> $chain
     * @return array<int, list<int>> area ID → page IDs, ascending
     */
    public function getSharedAreasInLocale(array $chain, bool $isDefault, bool $withSharedAreaTables): array
    {
        return $this->sharedAreasOf($this->getEligiblePagesInLocale($chain, $isDefault, $withSharedAreaTables));
    }

    /**
     * @param list<EligiblePage> $pages
     * @return array<int, list<int>> area ID → page IDs, ascending
     */
    private function sharedAreasOf(array $pages): array
    {
        $pagesByArea = [];
        foreach ($pages as $page) {
            $pagesByArea[$page['areaId']][] = $page['pageId'];
        }

        $shared = \array_filter($pagesByArea, static fn (array $pageIds): bool => \count($pageIds) > 1);
        \ksort($shared);

        return \array_map(static function (array $pageIds): array {
            \sort($pageIds);

            return $pageIds;
        }, $shared);
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
        if (!$this->tableExists($table)) {
            return false;
        }

        if (!isset($this->fieldListCache[$table])) {
            /** @var array<string, mixed> $columns */
            $columns = DB::field_list($table);
            $this->fieldListCache[$table] = $columns;
        }

        return \array_key_exists($column, $this->fieldListCache[$table]);
    }

    private function tableExists(string $table): bool
    {
        if ($this->tableListCache === null) {
            /** @var array<string, mixed> $tableList */
            $tableList = DB::table_list();
            $this->tableListCache = $tableList;
        }

        // table_list() returns lowercase table names as keys
        return \array_key_exists(\strtolower($table), $this->tableListCache);
    }

    /**
     * Find page tables that have a given column.
     *
     * @return list<string> Base table names (without stage suffix)
     */
    private function findPageTablesWithColumn(string $column): array
    {
        return \array_values(\array_filter(
            $this->findPageTables(),
            fn (string $table): bool => $this->tableHasColumn($table, $column),
        ));
    }

    /**
     * Every existing page table, discovered through the class manifest's
     * SiteTree subclasses. Only scans page tables, not the entire database.
     *
     * @return list<string> Base table names (without stage suffix)
     */
    private function findPageTables(): array
    {
        $schema = DataObject::getSchema();
        $tables = [];

        foreach (ClassInfo::subclassesFor(SiteTree::class, true) as $class) {
            $table = $schema->tableName($class);
            if ($table !== null && !isset($tables[$table]) && $this->tableExists($table)) {
                $tables[$table] = true;
            }
        }

        return \array_keys($tables);
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
