<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Creates and seeds old elemental module tables for integration tests.
 *
 * The old dnadesign/silverstripe-elemental tables don't exist in the new
 * module's schema, so this helper creates them using raw DDL. All operations
 * use DB::query() for DDL and DB::prepared_query() for parameterized inserts.
 */
final class LegacyTableSeeder
{
    private const array LEGACY_TABLES = [
        'BaseElement',
        'BaseElement_Live',
        'ElementRow',
        'ElementRow_Live',
        'ElementContent',
        'ElementContent_Live',
        'ElementalArea',
        'ElementalArea_Live',
    ];

    /**
     * Create all legacy tables (idempotent — uses IF NOT EXISTS).
     *
     * Does NOT add extension columns to any page table. Call
     * {@see addExtensionColumns()} separately to simulate where the old
     * elemental extension was applied.
     */
    public function createTables(): void
    {
        $this->ensureDatabaseSelected();
        $this->createBaseElementTable('BaseElement');
        $this->createBaseElementTable('BaseElement_Live');
        $this->createElementRowTable('ElementRow');
        $this->createElementRowTable('ElementRow_Live');
        $this->createElementContentTable('ElementContent');
        $this->createElementContentTable('ElementContent_Live');
        $this->createElementalAreaTable('ElementalArea');
        $this->createElementalAreaTable('ElementalArea_Live');
    }

    /**
     * Reselect the configured database if the connection has none selected.
     *
     * Test classes that issue raw DDL but declare neither `$extra_dataobjects`
     * nor a `$fixture_file` never trigger SapphireTest's schema rebuild, which
     * is the step that (re)selects the database on the connection. When such a
     * class runs after a `usesTransactions = false` class whose DDL teardown
     * left the connection with no database selected (SapphireTest's
     * tearDownAfterClass deselects the temp DB), the next class's DDL fails with
     * "No database selected". Reselecting here makes the seeder self-sufficient
     * at the DDL boundary, independent of test-class run order.
     */
    private function ensureDatabaseSelected(): void
    {
        $conn = DB::get_conn();

        if ($conn->getSelectedDatabase() !== '' && $conn->getSelectedDatabase() !== null) {
            return;
        }

        $config = DB::getConfig();
        $database = \is_array($config) ? ($config['database'] ?? '') : '';

        if (!\is_string($database) || $database === '') {
            return;
        }

        $conn->selectDatabase($database, false, false);
    }

    /**
     * Remove all data from legacy tables without dropping them.
     *
     * DDL (CREATE TABLE) causes implicit commits in MySQL, so SapphireTest's
     * transaction rollback cannot clean up data in these tables between tests.
     *
     * Also resets extension columns on any page table that has them, so each
     * test starts with a clean slate regardless of which table the extension
     * was applied to.
     */
    public function truncateTables(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            DB::query("DELETE FROM \"{$table}\"");
        }

        foreach ($this->findTablesWithExtensionColumns() as [$table, $hasUseElementalGrid]) {
            foreach ([$table, $table . '_Live'] as $target) {
                $columns = DB::field_list($target);
                $hasFlag = \array_key_exists('UseElementalGrid', $columns);
                $hasArea = \array_key_exists('ElementalAreaID', $columns);

                if ($hasFlag) {
                    DB::query("UPDATE \"{$target}\" SET \"UseElementalGrid\" = 0, \"ElementalAreaID\" = 0");
                } elseif ($hasArea) {
                    DB::query("UPDATE \"{$target}\" SET \"ElementalAreaID\" = 0");
                }
            }
        }
    }

    /**
     * Drop all legacy tables.
     *
     * Does NOT remove extension columns from page tables. Call
     * {@see removeExtensionColumns()} separately.
     */
    public function dropTables(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            DB::query("DROP TABLE IF EXISTS \"{$table}\"");
        }
    }

    /**
     * Add UseElementalGrid and ElementalAreaID columns to a page table.
     *
     * Simulates the old elemental extension being applied to a specific class.
     * Adds columns to both draft and _Live tables, matching dev/build behaviour.
     * For example, `addExtensionColumns('SiteTree')` when the extension was on
     * SiteTree, or `addExtensionColumns('Page')` when it was on Page.
     */
    public function addExtensionColumns(string $table): void
    {
        foreach ([$table, $table . '_Live'] as $target) {
            $columns = DB::field_list($target);

            if (!\array_key_exists('UseElementalGrid', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" ADD COLUMN \"UseElementalGrid\" tinyint NOT NULL DEFAULT 0");
            }

            if (!\array_key_exists('ElementalAreaID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" ADD COLUMN \"ElementalAreaID\" int NOT NULL DEFAULT 0");
            }
        }
    }

    /**
     * Remove extension columns from a page table (draft and _Live).
     */
    public function removeExtensionColumns(string $table): void
    {
        foreach ([$table, $table . '_Live'] as $target) {
            $columns = DB::field_list($target);

            if (\array_key_exists('UseElementalGrid', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" DROP COLUMN \"UseElementalGrid\"");
            }

            if (\array_key_exists('ElementalAreaID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" DROP COLUMN \"ElementalAreaID\"");
            }
        }
    }

    /**
     * Add only ElementalAreaID to a page table (no UseElementalGrid).
     *
     * Simulates plain dnadesign/silverstripe-elemental without the WeDevelop
     * grid extension. Pages are eligible for migration based solely on having
     * a valid ElementalAreaID. Adds to both draft and _Live tables.
     */
    public function addElementalAreaColumn(string $table): void
    {
        foreach ([$table, $table . '_Live'] as $target) {
            $columns = DB::field_list($target);

            if (!\array_key_exists('ElementalAreaID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" ADD COLUMN \"ElementalAreaID\" int NOT NULL DEFAULT 0");
            }
        }
    }

    /**
     * Remove only the ElementalAreaID column from a page table (draft and _Live).
     */
    public function removeElementalAreaColumn(string $table): void
    {
        foreach ([$table, $table . '_Live'] as $target) {
            $columns = DB::field_list($target);

            if (\array_key_exists('ElementalAreaID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" DROP COLUMN \"ElementalAreaID\"");
            }
        }
    }

    /**
     * Seed a plain elemental page (ElementalAreaID only, no UseElementalGrid).
     */
    public function seedPlainElementalPage(int $pageId, int $areaId): void
    {
        $this->seedPlainElementalPageOnTable('Page', $pageId, $areaId);
    }

    /**
     * Seed a plain elemental page on a specific table.
     *
     * Sets only ElementalAreaID (no UseElementalGrid column expected).
     */
    public function seedPlainElementalPageOnTable(string $table, int $pageId, int $areaId): void
    {
        DB::prepared_query(
            "UPDATE \"{$table}\" SET \"ElementalAreaID\" = ? WHERE \"ID\" = ?",
            [$areaId, $pageId],
        );

        DB::prepared_query(
            'INSERT INTO "ElementalArea" ("ID", "OwnerClassName") VALUES (?, ?)',
            [$areaId, 'SilverStripe\\CMS\\Model\\SiteTree'],
        );
    }

    /**
     * Seed a page with UseElementalGrid and ElementalAreaID on Page,
     * plus an ElementalArea row.
     */
    public function seedPage(int $pageId, int $areaId, bool $useGrid = true): void
    {
        $this->seedPageOnTable('Page', $pageId, $areaId, $useGrid);
    }

    /**
     * Seed eligible page data on a specific table.
     *
     * Updates the extension columns on the given table and creates the
     * corresponding ElementalArea row.
     */
    public function seedPageOnTable(string $table, int $pageId, int $areaId, bool $useGrid = true): void
    {
        DB::prepared_query(
            "UPDATE \"{$table}\" SET \"UseElementalGrid\" = ?, \"ElementalAreaID\" = ? WHERE \"ID\" = ?",
            [$useGrid ? 1 : 0, $areaId, $pageId],
        );

        DB::prepared_query(
            'INSERT INTO "ElementalArea" ("ID", "OwnerClassName") VALUES (?, ?)',
            [$areaId, 'SilverStripe\\CMS\\Model\\SiteTree'],
        );
    }

    /**
     * Seed a BaseElement row.
     *
     * @param array<string, mixed> $overrides Column overrides (e.g. SizeMD, OffsetLG, VisibilityXS)
     */
    public function seedElement(
        int $id,
        int $areaId,
        string $className,
        int $sort,
        array $overrides = [],
        string $stage = 'draft',
    ): void {
        $table = $this->stageTable('BaseElement', $stage);

        $defaults = [
            'ID' => $id,
            'ClassName' => $className,
            'Title' => '',
            'ShowTitle' => 0,
            'TitleTag' => '',
            'TitleClass' => '',
            'Sort' => $sort,
            'ExtraClass' => '',
            'ParentID' => $areaId,
            'SizeXS' => 0,
            'SizeSM' => 0,
            'SizeMD' => 0,
            'SizeLG' => 0,
            'SizeXL' => 0,
            'OffsetXS' => 0,
            'OffsetSM' => 0,
            'OffsetMD' => 0,
            'OffsetLG' => 0,
            'OffsetXL' => 0,
            'VisibilityXS' => null,
            'VisibilitySM' => null,
            'VisibilityMD' => null,
            'VisibilityLG' => null,
            'VisibilityXL' => null,
        ];

        $data = \array_merge($defaults, $overrides);

        $columns = \implode('", "', \array_keys($data));
        $placeholders = \implode(', ', \array_fill(0, \count($data), '?'));

        DB::prepared_query(
            "INSERT INTO \"{$table}\" (\"{$columns}\") VALUES ({$placeholders})",
            \array_values($data),
        );
    }

    /**
     * Seed an ElementRow row.
     */
    public function seedRow(
        int $elementId,
        bool $isFluid = false,
        string $customSectionClass = '',
        string $stage = 'draft',
    ): void {
        $table = $this->stageTable('ElementRow', $stage);

        DB::prepared_query(
            "INSERT INTO \"{$table}\" (\"ID\", \"IsFluid\", \"CustomSectionClass\") VALUES (?, ?, ?)",
            [$elementId, $isFluid ? 1 : 0, $customSectionClass],
        );
    }

    /**
     * Seed an ElementContent row with media extension fields.
     *
     * @param array<string, mixed> $fields Media field values
     */
    public function seedContentMedia(
        int $elementId,
        array $fields = [],
        string $stage = 'draft',
    ): void {
        $table = $this->stageTable('ElementContent', $stage);

        $defaults = [
            'ID' => $elementId,
            'HTML' => '',
            'ContentColumns' => '',
            'ContentVerticalAlign' => '',
            'ExtraColumnGap' => 0,
            'MediaType' => '',
            'MediaCaption' => '',
            'MediaRatio' => '',
            'MediaPosition' => '',
            'MediaImageID' => 0,
            'MediaVideoFullURL' => '',
            'MediaVideoProvider' => '',
            'MediaVideoHasOverlay' => 0,
            'MediaVideoCustomThumbnailID' => 0,
            'MediaVideoEmbeddedName' => '',
            'MediaVideoEmbeddedURL' => '',
            'MediaVideoEmbeddedDescription' => '',
            'MediaVideoEmbeddedThumbnail' => '',
            'MediaVideoEmbeddedCreated' => '',
        ];

        $data = \array_merge($defaults, $fields);

        $columns = \implode('", "', \array_keys($data));
        $placeholders = \implode(', ', \array_fill(0, \count($data), '?'));

        DB::prepared_query(
            "INSERT INTO \"{$table}\" (\"{$columns}\") VALUES ({$placeholders})",
            \array_values($data),
        );
    }

    public function addFieldLocalisedTables(): void
    {
        foreach (['BaseElement_Localised', 'BaseElement_Localised_Live'] as $target) {
            DB::query(<<<SQL
                CREATE TABLE IF NOT EXISTS "{$target}" (
                    "ID" int NOT NULL PRIMARY KEY AUTO_INCREMENT,
                    "RecordID" int NOT NULL DEFAULT 0,
                    "Locale" varchar(10) NOT NULL DEFAULT '',
                    "Title" varchar(255) DEFAULT NULL
                )
                SQL);
        }

        foreach (['ElementContent_Localised', 'ElementContent_Localised_Live'] as $target) {
            DB::query(<<<SQL
                CREATE TABLE IF NOT EXISTS "{$target}" (
                    "ID" int NOT NULL PRIMARY KEY AUTO_INCREMENT,
                    "RecordID" int NOT NULL DEFAULT 0,
                    "Locale" varchar(10) NOT NULL DEFAULT '',
                    "HTML" mediumtext
                )
                SQL);
        }
    }

    public function removeFieldLocalisedTables(): void
    {
        foreach ([
            'BaseElement_Localised', 'BaseElement_Localised_Live',
            'ElementContent_Localised', 'ElementContent_Localised_Live',
        ] as $target) {
            DB::query("DROP TABLE IF EXISTS \"{$target}\"");
        }
    }

    public function addLocaleIdColumn(): void
    {
        foreach (['BaseElement', 'BaseElement_Live'] as $target) {
            $columns = DB::field_list($target);
            if (!\array_key_exists('LocaleID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" ADD COLUMN \"LocaleID\" int NOT NULL DEFAULT 0");
            }
        }
    }

    public function removeLocaleIdColumn(): void
    {
        foreach (['BaseElement', 'BaseElement_Live'] as $target) {
            $columns = DB::field_list($target);
            if (\array_key_exists('LocaleID', $columns)) {
                DB::query("ALTER TABLE \"{$target}\" DROP COLUMN \"LocaleID\"");
            }
        }
    }

    /**
     * @param array<string, mixed> $fields Localised column overrides (e.g. ['Title' => 'NL Title'])
     */
    public function seedLocalisedElement(int $recordId, string $locale, array $fields, string $stage = 'draft'): void
    {
        $target = \strtolower($stage) === 'live' ? 'BaseElement_Localised_Live' : 'BaseElement_Localised';
        $data = \array_merge(['RecordID' => $recordId, 'Locale' => $locale], $fields);
        $columns = \implode('", "', \array_keys($data));
        $placeholders = \implode(', ', \array_fill(0, \count($data), '?'));
        DB::prepared_query(
            "INSERT INTO \"{$target}\" (\"{$columns}\") VALUES ({$placeholders})",
            \array_values($data),
        );
    }

    /**
     * @param array<string, mixed> $fields Localised column overrides (e.g. ['HTML' => '<p>NL</p>'])
     */
    public function seedLocalisedContent(int $recordId, string $locale, array $fields, string $stage = 'draft'): void
    {
        $target = \strtolower($stage) === 'live' ? 'ElementContent_Localised_Live' : 'ElementContent_Localised';
        $data = \array_merge(['RecordID' => $recordId, 'Locale' => $locale], $fields);
        $columns = \implode('", "', \array_keys($data));
        $placeholders = \implode(', ', \array_fill(0, \count($data), '?'));
        DB::prepared_query(
            "INSERT INTO \"{$target}\" (\"{$columns}\") VALUES ({$placeholders})",
            \array_values($data),
        );
    }

    private function createBaseElementTable(string $name): void
    {
        DB::query(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" (
                "ID" int NOT NULL PRIMARY KEY,
                "ClassName" varchar(255) NOT NULL DEFAULT '',
                "Title" varchar(255) NOT NULL DEFAULT '',
                "ShowTitle" tinyint NOT NULL DEFAULT 0,
                "TitleTag" varchar(255) NOT NULL DEFAULT '',
                "TitleClass" varchar(255) NOT NULL DEFAULT '',
                "Sort" int NOT NULL DEFAULT 0,
                "ExtraClass" varchar(255) NOT NULL DEFAULT '',
                "ParentID" int NOT NULL DEFAULT 0,
                "SizeXS" int NOT NULL DEFAULT 0,
                "SizeSM" int NOT NULL DEFAULT 0,
                "SizeMD" int NOT NULL DEFAULT 0,
                "SizeLG" int NOT NULL DEFAULT 0,
                "SizeXL" int NOT NULL DEFAULT 0,
                "OffsetXS" int NOT NULL DEFAULT 0,
                "OffsetSM" int NOT NULL DEFAULT 0,
                "OffsetMD" int NOT NULL DEFAULT 0,
                "OffsetLG" int NOT NULL DEFAULT 0,
                "OffsetXL" int NOT NULL DEFAULT 0,
                "VisibilityXS" varchar(50) DEFAULT NULL,
                "VisibilitySM" varchar(50) DEFAULT NULL,
                "VisibilityMD" varchar(50) DEFAULT NULL,
                "VisibilityLG" varchar(50) DEFAULT NULL,
                "VisibilityXL" varchar(50) DEFAULT NULL
            )
            SQL);
    }

    private function createElementRowTable(string $name): void
    {
        DB::query(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" (
                "ID" int NOT NULL PRIMARY KEY,
                "IsFluid" tinyint NOT NULL DEFAULT 0,
                "CustomSectionClass" varchar(255) NOT NULL DEFAULT ''
            )
            SQL);
    }

    private function createElementContentTable(string $name): void
    {
        DB::query(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" (
                "ID" int NOT NULL PRIMARY KEY,
                "HTML" mediumtext,
                "ContentColumns" varchar(50) NOT NULL DEFAULT '',
                "ContentVerticalAlign" varchar(100) NOT NULL DEFAULT '',
                "ExtraColumnGap" int NOT NULL DEFAULT 0,
                "MediaType" varchar(50) NOT NULL DEFAULT '',
                "MediaCaption" varchar(255) NOT NULL DEFAULT '',
                "MediaRatio" varchar(50) NOT NULL DEFAULT '',
                "MediaPosition" varchar(100) NOT NULL DEFAULT '',
                "MediaImageID" int NOT NULL DEFAULT 0,
                "MediaVideoFullURL" varchar(512) NOT NULL DEFAULT '',
                "MediaVideoProvider" varchar(100) NOT NULL DEFAULT '',
                "MediaVideoHasOverlay" tinyint NOT NULL DEFAULT 0,
                "MediaVideoCustomThumbnailID" int NOT NULL DEFAULT 0,
                "MediaVideoEmbeddedName" varchar(255) NOT NULL DEFAULT '',
                "MediaVideoEmbeddedURL" varchar(512) NOT NULL DEFAULT '',
                "MediaVideoEmbeddedDescription" text,
                "MediaVideoEmbeddedThumbnail" varchar(512) NOT NULL DEFAULT '',
                "MediaVideoEmbeddedCreated" varchar(100) NOT NULL DEFAULT ''
            )
            SQL);
    }

    private function createElementalAreaTable(string $name): void
    {
        DB::query(<<<SQL
            CREATE TABLE IF NOT EXISTS "{$name}" (
                "ID" int NOT NULL PRIMARY KEY,
                "OwnerClassName" varchar(255) NOT NULL DEFAULT ''
            )
            SQL);
    }

    /**
     * Find all SiteTree subclass tables that currently have extension columns.
     *
     * Used by {@see truncateTables()} to reset data regardless of which table
     * the extension was applied to. Detects tables with either UseElementalGrid
     * (WeDevelop grid) or only ElementalAreaID (plain elemental).
     *
     * @return list<array{string, bool}> Each entry is [tableName, hasUseElementalGrid]
     */
    private function findTablesWithExtensionColumns(): array
    {
        $schema = DataObject::getSchema();
        $existingTables = DB::table_list();
        $result = [];
        $checked = [];

        foreach (ClassInfo::subclassesFor(SiteTree::class, true) as $class) {
            $table = $schema->tableName($class);
            if ($table === null || isset($checked[$table])) {
                continue;
            }
            $checked[$table] = true;

            // table_list() returns lowercase keys
            if (!\array_key_exists(\strtolower($table), $existingTables)) {
                continue;
            }

            $columns = DB::field_list($table);
            $hasUseElementalGrid = \array_key_exists('UseElementalGrid', $columns);
            $hasElementalAreaID = \array_key_exists('ElementalAreaID', $columns);

            if ($hasUseElementalGrid || $hasElementalAreaID) {
                $result[] = [$table, $hasUseElementalGrid];
            }
        }

        return $result;
    }

    private function stageTable(string $baseTable, string $stage): string
    {
        return \strtolower($stage) === 'live' ? $baseTable . '_Live' : $baseTable;
    }
}
