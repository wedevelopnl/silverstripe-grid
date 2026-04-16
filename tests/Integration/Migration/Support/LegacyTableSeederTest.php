<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Support;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

#[CoversClass(LegacyTableSeeder::class)]
final class LegacyTableSeederTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected $usesTransactions = false;

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

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->seeder = new LegacyTableSeeder();
    }

    protected function tearDown(): void
    {
        $this->seeder->dropTables();
        $this->seeder->removeExtensionColumns('Page');

        parent::tearDown();
    }

    // ─── Group 1: createTables ──────────────────────────────────

    public function testCreateTablesCreatesAllEightTables(): void
    {
        $this->seeder->createTables();

        $tables = DB::table_list();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertArrayHasKey(\strtolower($table), $tables, "Table {$table} should exist");
        }
    }

    public function testCreateTablesIsIdempotent(): void
    {
        $this->seeder->createTables();
        $this->seeder->createTables();

        $tables = DB::table_list();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertArrayHasKey(\strtolower($table), $tables, "Table {$table} should still exist after second call");
        }
    }

    public function testBaseElementTableHasExpectedColumns(): void
    {
        $this->seeder->createTables();

        $columns = \array_keys(DB::field_list('BaseElement'));

        $expected = [
            'ID', 'ClassName', 'Title', 'ShowTitle', 'TitleTag', 'TitleClass',
            'Sort', 'ExtraClass', 'ParentID',
            'SizeXS', 'SizeSM', 'SizeMD', 'SizeLG', 'SizeXL',
            'OffsetXS', 'OffsetSM', 'OffsetMD', 'OffsetLG', 'OffsetXL',
            'VisibilityXS', 'VisibilitySM', 'VisibilityMD', 'VisibilityLG', 'VisibilityXL',
        ];

        foreach ($expected as $column) {
            self::assertContains($column, $columns, "BaseElement should have column {$column}");
        }
    }

    public function testElementRowTableHasExpectedColumns(): void
    {
        $this->seeder->createTables();

        $columns = \array_keys(DB::field_list('ElementRow'));

        foreach (['ID', 'IsFluid', 'CustomSectionClass'] as $column) {
            self::assertContains($column, $columns, "ElementRow should have column {$column}");
        }
    }

    public function testElementContentTableHasExpectedColumns(): void
    {
        $this->seeder->createTables();

        $columns = \array_keys(DB::field_list('ElementContent'));

        $expected = [
            'ID', 'HTML', 'ContentColumns', 'ContentVerticalAlign', 'ExtraColumnGap',
            'MediaType', 'MediaCaption', 'MediaRatio', 'MediaPosition', 'MediaImageID',
            'MediaVideoFullURL', 'MediaVideoProvider', 'MediaVideoHasOverlay',
            'MediaVideoCustomThumbnailID', 'MediaVideoEmbeddedName', 'MediaVideoEmbeddedURL',
            'MediaVideoEmbeddedDescription', 'MediaVideoEmbeddedThumbnail', 'MediaVideoEmbeddedCreated',
        ];

        foreach ($expected as $column) {
            self::assertContains($column, $columns, "ElementContent should have column {$column}");
        }
    }

    public function testElementalAreaTableHasExpectedColumns(): void
    {
        $this->seeder->createTables();

        $columns = \array_keys(DB::field_list('ElementalArea'));

        foreach (['ID', 'OwnerClassName'] as $column) {
            self::assertContains($column, $columns, "ElementalArea should have column {$column}");
        }
    }

    // ─── Group 2: dropTables ────────────────────────────────────

    public function testDropTablesRemovesAllTables(): void
    {
        $this->seeder->createTables();
        $this->seeder->dropTables();

        $tables = DB::table_list();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertArrayNotHasKey(\strtolower($table), $tables, "Table {$table} should not exist");
        }
    }

    public function testDropTablesIsIdempotent(): void
    {
        $this->seeder->createTables();
        $this->seeder->dropTables();
        $this->seeder->dropTables();

        $tables = DB::table_list();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertArrayNotHasKey(\strtolower($table), $tables);
        }
    }

    // ─── Group 3: Extension Columns ─────────────────────────────

    public function testAddExtensionColumnsAddsBothColumns(): void
    {
        $this->seeder->addExtensionColumns('Page');

        $columns = DB::field_list('Page');

        self::assertArrayHasKey('UseElementalGrid', $columns);
        self::assertArrayHasKey('ElementalAreaID', $columns);
    }

    public function testAddExtensionColumnsIsIdempotent(): void
    {
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->addExtensionColumns('Page');

        $columns = DB::field_list('Page');

        self::assertArrayHasKey('UseElementalGrid', $columns);
        self::assertArrayHasKey('ElementalAreaID', $columns);
    }

    public function testRemoveExtensionColumnsRemovesBothColumns(): void
    {
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->removeExtensionColumns('Page');

        $columns = DB::field_list('Page');

        self::assertArrayNotHasKey('UseElementalGrid', $columns);
        self::assertArrayNotHasKey('ElementalAreaID', $columns);
    }

    public function testRemoveExtensionColumnsIsIdempotent(): void
    {
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->removeExtensionColumns('Page');

        $columns = DB::field_list('Page');

        self::assertArrayNotHasKey('UseElementalGrid', $columns);
        self::assertArrayNotHasKey('ElementalAreaID', $columns);
    }

    // ─── Group 4: seedPage / seedPageOnTable ────────────────────

    public function testSeedPageSetsExtensionColumnsAndCreatesArea(): void
    {
        $this->setUpTablesAndExtensions();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 100);

        $row = DB::prepared_query(
            'SELECT "UseElementalGrid", "ElementalAreaID" FROM "Page" WHERE "ID" = ?',
            [$pageId],
        )->record();

        self::assertSame(1, (int) $row['UseElementalGrid']);
        self::assertSame(100, (int) $row['ElementalAreaID']);

        $area = DB::prepared_query(
            'SELECT "OwnerClassName" FROM "ElementalArea" WHERE "ID" = ?',
            [100],
        )->record();

        self::assertNotEmpty($area);
        self::assertSame(SiteTree::class, $area['OwnerClassName']);
    }

    public function testSeedPageWithUseGridFalse(): void
    {
        $this->setUpTablesAndExtensions();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 101, useGrid: false);

        $row = DB::prepared_query(
            'SELECT "UseElementalGrid" FROM "Page" WHERE "ID" = ?',
            [$pageId],
        )->record();

        self::assertSame(0, (int) $row['UseElementalGrid']);
    }

    public function testSeedPageOnTableTargetsSpecificTable(): void
    {
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('SiteTree');

        try {
            $page = $this->objFromFixture(Page::class, 'test_page');
            $pageId = (int) $page->ID;

            $this->seeder->seedPageOnTable('SiteTree', $pageId, 102);

            $row = DB::prepared_query(
                'SELECT "UseElementalGrid", "ElementalAreaID" FROM "SiteTree" WHERE "ID" = ?',
                [$pageId],
            )->record();

            self::assertSame(1, (int) $row['UseElementalGrid']);
            self::assertSame(102, (int) $row['ElementalAreaID']);
        } finally {
            $this->seeder->removeExtensionColumns('SiteTree');
        }
    }

    // ─── Group 5: seedElement ───────────────────────────────────

    public function testSeedElementInsertsDraftRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedElement(1, 500, 'App\\Model\\TestElement', 3);

        $row = DB::query('SELECT * FROM "BaseElement" WHERE "ID" = 1')->record();

        self::assertNotEmpty($row);
        self::assertSame(500, (int) $row['ParentID']);
        self::assertSame('App\\Model\\TestElement', $row['ClassName']);
        self::assertSame(3, (int) $row['Sort']);
        self::assertSame(0, (int) $row['SizeMD']);
        self::assertNull($row['VisibilityXS']);
    }

    public function testSeedElementInsertsLiveRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedElement(2, 500, 'App\\Model\\TestElement', 1, stage: 'live');

        $liveRow = DB::query('SELECT "ID" FROM "BaseElement_Live" WHERE "ID" = 2')->record();
        self::assertNotEmpty($liveRow);

        $draftRow = DB::query('SELECT "ID" FROM "BaseElement" WHERE "ID" = 2')->record();
        self::assertEmpty($draftRow);
    }

    public function testSeedElementAppliesOverrides(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedElement(3, 500, 'App\\Model\\TestElement', 1, [
            'SizeMD' => 8,
            'OffsetLG' => 2,
            'VisibilityXS' => 'hidden',
            'Title' => 'Custom Title',
        ]);

        $row = DB::query('SELECT * FROM "BaseElement" WHERE "ID" = 3')->record();

        self::assertSame(8, (int) $row['SizeMD']);
        self::assertSame(2, (int) $row['OffsetLG']);
        self::assertSame('hidden', $row['VisibilityXS']);
        self::assertSame('Custom Title', $row['Title']);

        // Non-overridden fields retain defaults
        self::assertSame(0, (int) $row['SizeXS']);
        self::assertNull($row['VisibilityMD']);
    }

    // ─── Group 6: seedRow ───────────────────────────────────────

    public function testSeedRowInsertsDraftRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedRow(10, isFluid: true, customSectionClass: 'my-section');

        $row = DB::query('SELECT * FROM "ElementRow" WHERE "ID" = 10')->record();

        self::assertNotEmpty($row);
        self::assertSame(1, (int) $row['IsFluid']);
        self::assertSame('my-section', $row['CustomSectionClass']);
    }

    public function testSeedRowInsertsLiveRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedRow(11, stage: 'live');

        $liveRow = DB::query('SELECT "ID" FROM "ElementRow_Live" WHERE "ID" = 11')->record();
        self::assertNotEmpty($liveRow);

        $draftRow = DB::query('SELECT "ID" FROM "ElementRow" WHERE "ID" = 11')->record();
        self::assertEmpty($draftRow);
    }

    public function testSeedRowUsesDefaults(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedRow(12);

        $row = DB::query('SELECT * FROM "ElementRow" WHERE "ID" = 12')->record();

        self::assertSame(0, (int) $row['IsFluid']);
        self::assertSame('', $row['CustomSectionClass']);
    }

    // ─── Group 7: seedContentMedia ──────────────────────────────

    public function testSeedContentMediaInsertsDraftRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedContentMedia(20);

        $row = DB::query('SELECT * FROM "ElementContent" WHERE "ID" = 20')->record();

        self::assertNotEmpty($row);
        self::assertSame('', $row['MediaType']);
        self::assertSame(0, (int) $row['ExtraColumnGap']);
    }

    public function testSeedContentMediaInsertsLiveRow(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedContentMedia(21, stage: 'live');

        $liveRow = DB::query('SELECT "ID" FROM "ElementContent_Live" WHERE "ID" = 21')->record();
        self::assertNotEmpty($liveRow);

        $draftRow = DB::query('SELECT "ID" FROM "ElementContent" WHERE "ID" = 21')->record();
        self::assertEmpty($draftRow);
    }

    public function testSeedContentMediaAppliesFieldOverrides(): void
    {
        $this->seeder->createTables();

        $this->seeder->seedContentMedia(22, [
            'HTML' => '<p>Hello</p>',
            'MediaType' => 'image',
            'MediaRatio' => '16x9',
            'MediaImageID' => 42,
        ]);

        $row = DB::query('SELECT * FROM "ElementContent" WHERE "ID" = 22')->record();

        self::assertSame('<p>Hello</p>', $row['HTML']);
        self::assertSame('image', $row['MediaType']);
        self::assertSame('16x9', $row['MediaRatio']);
        self::assertSame(42, (int) $row['MediaImageID']);

        // Non-overridden fields retain defaults
        self::assertSame(0, (int) $row['ExtraColumnGap']);
        self::assertSame('', $row['MediaVideoProvider']);
    }

    // ─── Group 8: truncateTables ────────────────────────────────

    public function testTruncateTablesClearsAllLegacyTables(): void
    {
        $this->setUpTablesAndExtensions();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(1, 100, 'App\\Model\\TestElement', 1);
        $this->seeder->seedElement(2, 100, 'App\\Model\\TestElement', 2, stage: 'live');
        $this->seeder->seedRow(1);
        $this->seeder->seedRow(2, stage: 'live');
        $this->seeder->seedContentMedia(1);
        $this->seeder->seedContentMedia(2, stage: 'live');

        $this->seeder->truncateTables();

        foreach (self::LEGACY_TABLES as $table) {
            $count = (int) DB::query("SELECT COUNT(*) FROM \"{$table}\"")->value();
            self::assertSame(0, $count, "Table {$table} should be empty after truncate");
        }
    }

    public function testTruncateTablesResetsExtensionColumns(): void
    {
        $this->setUpTablesAndExtensions();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 100);
        $this->seeder->truncateTables();

        $row = DB::prepared_query(
            'SELECT "UseElementalGrid", "ElementalAreaID" FROM "Page" WHERE "ID" = ?',
            [$pageId],
        )->record();

        self::assertSame(0, (int) $row['UseElementalGrid']);
        self::assertSame(0, (int) $row['ElementalAreaID']);
    }

    public function testTruncateTablesDoesNotDropTables(): void
    {
        $this->setUpTablesAndExtensions();

        $this->seeder->truncateTables();

        $tables = DB::table_list();

        foreach (self::LEGACY_TABLES as $table) {
            self::assertArrayHasKey(\strtolower($table), $tables, "Table {$table} should still exist after truncate");
        }
    }

    // ─── Group 9: Plain Elemental (ElementalAreaID only) ───────

    public function testAddElementalAreaColumnAddsOnlyAreaId(): void
    {
        $this->seeder->addElementalAreaColumn('SiteTree');

        $columns = DB::field_list('SiteTree');

        self::assertArrayHasKey('ElementalAreaID', $columns);
        self::assertArrayNotHasKey('UseElementalGrid', $columns);
    }

    public function testAddElementalAreaColumnIsIdempotent(): void
    {
        $this->seeder->addElementalAreaColumn('SiteTree');
        $this->seeder->addElementalAreaColumn('SiteTree');

        $columns = DB::field_list('SiteTree');

        self::assertArrayHasKey('ElementalAreaID', $columns);
    }

    public function testRemoveElementalAreaColumnDropsOnlyAreaId(): void
    {
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->removeElementalAreaColumn('Page');

        $columns = DB::field_list('Page');

        self::assertArrayNotHasKey('ElementalAreaID', $columns);
        self::assertArrayHasKey('UseElementalGrid', $columns, 'UseElementalGrid should be untouched');

        // Clean up the remaining column
        DB::query('ALTER TABLE "Page" DROP COLUMN "UseElementalGrid"');
    }

    public function testRemoveElementalAreaColumnIsIdempotent(): void
    {
        $this->seeder->addElementalAreaColumn('SiteTree');
        $this->seeder->removeElementalAreaColumn('SiteTree');
        $this->seeder->removeElementalAreaColumn('SiteTree');

        $columns = DB::field_list('SiteTree');

        self::assertArrayNotHasKey('ElementalAreaID', $columns);
    }

    public function testSeedPlainElementalPageSetsAreaIdWithoutUseElementalGrid(): void
    {
        $this->seeder->createTables();
        $this->seeder->addElementalAreaColumn('Page');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPlainElementalPage($pageId, 200);

        $row = DB::prepared_query(
            'SELECT "ElementalAreaID" FROM "Page" WHERE "ID" = ?',
            [$pageId],
        )->record();

        self::assertSame(200, (int) $row['ElementalAreaID']);

        // UseElementalGrid column should not exist at all
        $columns = DB::field_list('Page');
        self::assertArrayNotHasKey('UseElementalGrid', $columns);

        // ElementalArea row should be created
        $area = DB::prepared_query(
            'SELECT "OwnerClassName" FROM "ElementalArea" WHERE "ID" = ?',
            [200],
        )->record();

        self::assertNotEmpty($area);
        self::assertSame(SiteTree::class, $area['OwnerClassName']);

        $this->seeder->removeElementalAreaColumn('Page');
    }

    public function testTruncateTablesResetsElementalAreaOnlyColumns(): void
    {
        $this->seeder->createTables();
        $this->seeder->addElementalAreaColumn('Page');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPlainElementalPage($pageId, 300);
        $this->seeder->truncateTables();

        $row = DB::prepared_query(
            'SELECT "ElementalAreaID" FROM "Page" WHERE "ID" = ?',
            [$pageId],
        )->record();

        self::assertSame(0, (int) $row['ElementalAreaID']);

        $this->seeder->removeElementalAreaColumn('SiteTree');
    }

    // ─── Helpers ────────────────────────────────────────────────

    private function setUpTablesAndExtensions(): void
    {
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
    }
}
