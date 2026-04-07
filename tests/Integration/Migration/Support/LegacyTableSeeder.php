<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

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
     */
    public function createTables(): void
    {
        $this->createBaseElementTable('BaseElement');
        $this->createBaseElementTable('BaseElement_Live');
        $this->createElementRowTable('ElementRow');
        $this->createElementRowTable('ElementRow_Live');
        $this->createElementContentTable('ElementContent');
        $this->createElementContentTable('ElementContent_Live');
        $this->createElementalAreaTable('ElementalArea');
        $this->createElementalAreaTable('ElementalArea_Live');
        $this->addSiteTreeColumns();
    }

    /**
     * Remove all data from legacy tables without dropping them.
     *
     * DDL (CREATE TABLE) causes implicit commits in MySQL, so SapphireTest's
     * transaction rollback cannot clean up data in these tables between tests.
     */
    public function truncateTables(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            DB::query("DELETE FROM \"{$table}\"");
        }

        // Reset the grid columns on SiteTree rows
        $columns = DB::field_list('SiteTree');
        if (array_key_exists('UseElementalGrid', $columns)) {
            DB::query('UPDATE "SiteTree" SET "UseElementalGrid" = 0, "ElementalAreaID" = 0');
        }
    }

    /**
     * Drop all legacy tables and remove added SiteTree columns.
     */
    public function dropTables(): void
    {
        foreach (self::LEGACY_TABLES as $table) {
            DB::query("DROP TABLE IF EXISTS \"{$table}\"");
        }

        $this->removeSiteTreeColumns();
    }

    /**
     * Seed a page with UseElementalGrid and ElementalAreaID on SiteTree,
     * plus an ElementalArea row.
     */
    public function seedPage(int $pageId, int $areaId, bool $useGrid = true): void
    {
        DB::prepared_query(
            'UPDATE "SiteTree" SET "UseElementalGrid" = ?, "ElementalAreaID" = ? WHERE "ID" = ?',
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

        $data = array_merge($defaults, $overrides);

        $columns = implode('", "', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        DB::prepared_query(
            "INSERT INTO \"{$table}\" (\"{$columns}\") VALUES ({$placeholders})",
            array_values($data),
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

        $data = array_merge($defaults, $fields);

        $columns = implode('", "', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        DB::prepared_query(
            "INSERT INTO \"{$table}\" (\"{$columns}\") VALUES ({$placeholders})",
            array_values($data),
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
     * Add UseElementalGrid and ElementalAreaID columns to SiteTree if missing.
     *
     * Uses a schema check rather than IF NOT EXISTS because MySQL's ALTER TABLE
     * does not support that syntax for ADD COLUMN consistently.
     */
    private function addSiteTreeColumns(): void
    {
        $columns = DB::field_list('SiteTree');

        if (!array_key_exists('UseElementalGrid', $columns)) {
            DB::query('ALTER TABLE "SiteTree" ADD COLUMN "UseElementalGrid" tinyint NOT NULL DEFAULT 0');
        }

        if (!array_key_exists('ElementalAreaID', $columns)) {
            DB::query('ALTER TABLE "SiteTree" ADD COLUMN "ElementalAreaID" int NOT NULL DEFAULT 0');
        }
    }

    /**
     * Remove the columns added to SiteTree (best-effort cleanup).
     */
    private function removeSiteTreeColumns(): void
    {
        $columns = DB::field_list('SiteTree');

        if (array_key_exists('UseElementalGrid', $columns)) {
            DB::query('ALTER TABLE "SiteTree" DROP COLUMN "UseElementalGrid"');
        }

        if (array_key_exists('ElementalAreaID', $columns)) {
            DB::query('ALTER TABLE "SiteTree" DROP COLUMN "ElementalAreaID"');
        }
    }

    private function stageTable(string $baseTable, string $stage): string
    {
        return strtolower($stage) === 'live' ? $baseTable . '_Live' : $baseTable;
    }
}
