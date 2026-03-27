<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Reports;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Reports\GridElementReport;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridElementReport::class)]
final class GridElementReportTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    private function report(): GridElementReport
    {
        return GridElementReport::create();
    }

    // ── Title / Description ─────────────────────────────────────

    public function testTitleReturnsNonEmptyString(): void
    {
        self::assertNotEmpty($this->report()->title());
    }

    public function testDescriptionReturnsNonEmptyString(): void
    {
        self::assertNotEmpty($this->report()->description());
    }

    // ── sourceRecords ───────────────────────────────────────────

    public function testSourceRecordsReturnsAllElements(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        GridTreeFactory::contentElement($column);

        $records = $this->report()->sourceRecords();

        // At least the 4 elements we created (section, row, column, content)
        self::assertGreaterThanOrEqual(4, $records->count());
    }

    public function testSourceRecordsFiltersByClassName(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        GridTreeFactory::section($page);
        $section2 = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section2);
        GridTreeFactory::column($row);

        $records = $this->report()->sourceRecords(['ClassName' => Section::class]);

        foreach ($records as $record) {
            self::assertInstanceOf(Section::class, $record);
        }
        self::assertGreaterThanOrEqual(2, $records->count());
    }

    public function testSourceRecordsFiltersByPageId(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $page2 = $this->objFromFixture(SiteTree::class, 'test_page_2');
        GridTreeFactory::section($page);
        GridTreeFactory::section($page2);

        // Filter to page1 only — PageID is passed as string (from form submission)
        $records = $this->report()->sourceRecords(['PageID' => (string) $page->ID]);

        foreach ($records as $record) {
            $recordPage = $record->getPage();
            self::assertInstanceOf(SiteTree::class, $recordPage);
            self::assertSame((int) $page->ID, (int) $recordPage->ID);
        }
    }

    public function testSourceRecordsFiltersOrphanedOnly(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $normalElement = GridTreeFactory::contentElement($column);

        // Create an orphan: write with valid parent, then orphan via raw SQL and reload
        $orphan = GridTreeFactory::contentElement($column);
        $orphanId = (int) $orphan->ID;
        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $orphanId,
        ));

        $records = $this->report()->sourceRecords(['PageID' => 'orphaned']);

        $ids = array_map('intval', $records->column('ID'));
        self::assertContains($orphanId, $ids);
        self::assertNotContains((int) $normalElement->ID, $ids);
    }

    public function testSourceRecordsEnrichesPageTitle(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $records = $this->report()->sourceRecords();

        $found = false;
        foreach ($records as $record) {
            if ((int) $record->ID === (int) $section->ID) {
                self::assertSame($page->Title, $record->PageTitle);
                self::assertNotNull($record->PageCMSLink);
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'Section should appear in sourceRecords');
    }

    public function testSourceRecordsOrphanGetsOrphanedLabel(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $orphan = GridTreeFactory::contentElement($column);
        $orphanId = (int) $orphan->ID;

        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $orphanId,
        ));

        $records = $this->report()->sourceRecords();

        foreach ($records as $record) {
            if ((int) $record->ID === $orphanId) {
                self::assertStringContainsString('Orphaned', $record->PageTitle);
                self::assertNull($record->PageCMSLink);
                return;
            }
        }
        self::fail('Orphan element should appear in sourceRecords');
    }

    // ── columns ─────────────────────────────────────────────────

    public function testColumnsReturnsExpectedKeys(): void
    {
        $columns = $this->report()->columns();

        self::assertArrayHasKey('Title', $columns);
        self::assertArrayHasKey('Type', $columns);
        self::assertArrayHasKey('PageTitle', $columns);
        self::assertArrayHasKey('LastEdited', $columns);

        foreach ($columns as $column) {
            self::assertArrayHasKey('title', $column);
        }
    }

    // ── parameterFields ─────────────────────────────────────────

    public function testParameterFieldsContainsPageAndTypeDropdowns(): void
    {
        $fields = $this->report()->parameterFields();

        $pageField = $fields->fieldByName('PageID');
        self::assertNotNull($pageField);
        self::assertInstanceOf(DropdownField::class, $pageField);

        $typeField = $fields->fieldByName('ClassName');
        self::assertNotNull($typeField);
        self::assertInstanceOf(DropdownField::class, $typeField);
    }
}
