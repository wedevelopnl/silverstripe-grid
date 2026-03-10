<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Reports;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Reports\GridElementReport;
use WeDevelop\Grid\Tests\Integration\Fixture\TestPage;

#[CoversClass(GridElementReport::class)]
final class GridElementReportTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** @var list<class-string> */
    protected static $extra_dataobjects = [
        TestPage::class,
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        TestPage::class => [
            GridPageExtension::class,
        ],
    ];

    private GridElementReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);

        $this->report = GridElementReport::create();
    }

    public function testReturnsElementsWithPageAssociation(): void
    {
        $page = $this->createPage('Test Page');

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $records = $this->report->sourceRecords();

        $sectionRecord = $this->findRecordById($records, $section->ID);
        $this->assertNotNull($sectionRecord);
        $this->assertSame('Test Page', $sectionRecord->PageTitle);
    }

    public function testOrphanedElementsMarkedAsOrphaned(): void
    {
        $section = Section::create();
        $section->Title = 'Orphan Section';
        $section->write();

        $records = $this->report->sourceRecords();

        $sectionRecord = $this->findRecordById($records, $section->ID);
        $this->assertNotNull($sectionRecord);
        $this->assertSame('Orphaned', $sectionRecord->PageTitle);
        $this->assertNull($sectionRecord->PageCMSLink);
    }

    public function testFilterByPage(): void
    {
        $pageA = $this->createPage('Page A');
        $pageB = $this->createPage('Page B');

        $sectionA = Section::create();
        $sectionA->ParentID = $pageA->ID;
        $sectionA->ParentClass = $pageA::class;
        $sectionA->write();

        $sectionB = Section::create();
        $sectionB->ParentID = $pageB->ID;
        $sectionB->ParentClass = $pageB::class;
        $sectionB->write();

        $records = $this->report->sourceRecords(['PageID' => (string) $pageA->ID]);

        // All returned elements should belong to Page A
        foreach ($records as $record) {
            $this->assertSame('Page A', $record->PageTitle);
        }

        // Section B should not be in the results
        $this->assertNull($this->findRecordById($records, $sectionB->ID));
    }

    public function testFilterByOrphaned(): void
    {
        $page = $this->createPage('Has Page');

        $attached = Section::create();
        $attached->ParentID = $page->ID;
        $attached->ParentClass = $page::class;
        $attached->write();

        $orphan = Section::create();
        $orphan->Title = 'Orphan';
        $orphan->write();

        $records = $this->report->sourceRecords(['PageID' => 'orphaned']);

        // Orphan should be present
        $this->assertNotNull($this->findRecordById($records, $orphan->ID));

        // Attached section (and its auto-scaffolded children) should not be present
        $this->assertNull($this->findRecordById($records, $attached->ID));
    }

    public function testFilterByElementType(): void
    {
        $page = $this->createPage('Type Filter');

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Auto-scaffolding creates Row + Column, so we have Section, Row, Column
        $records = $this->report->sourceRecords(['ClassName' => Section::class]);

        foreach ($records as $record) {
            $this->assertInstanceOf(Section::class, $record);
        }

        // Rows and Columns should be excluded
        $this->assertNull(
            $this->findRecordByClass($records, Row::class),
        );
        $this->assertNull(
            $this->findRecordByClass($records, Column::class),
        );
    }

    public function testNestedElementResolvesToOwningPage(): void
    {
        $page = $this->createPage('Nested Test');

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        // Auto-scaffolded Column is 3 levels deep
        $row = $section->getChildren()->first();
        $this->assertNotNull($row);
        $column = $row->getChildren()->first();
        $this->assertNotNull($column);

        $records = $this->report->sourceRecords();

        $columnRecord = $this->findRecordById($records, $column->ID);
        $this->assertNotNull($columnRecord);
        $this->assertSame('Nested Test', $columnRecord->PageTitle);
    }

    public function testColumnsDefinition(): void
    {
        $columns = $this->report->columns();

        $this->assertArrayHasKey('Title', $columns);
        $this->assertArrayHasKey('Type', $columns);
        $this->assertArrayHasKey('PageTitle', $columns);
        $this->assertArrayHasKey('LastEdited', $columns);
    }

    public function testParameterFieldsContainFilters(): void
    {
        $fields = $this->report->parameterFields();

        $this->assertNotNull($fields->fieldByName('PageID'));
        $this->assertNotNull($fields->fieldByName('ClassName'));
    }

    public function testPageTitleColumnFormattingRendersLinkForPageElement(): void
    {
        $page = $this->createPage('Linked Page');

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $records = $this->report->sourceRecords();
        $sectionRecord = $this->findRecordById($records, $section->ID);
        $this->assertNotNull($sectionRecord);

        $columns = $this->report->columns();
        $formatting = $columns['PageTitle']['formatting'];
        $output = $formatting($sectionRecord->PageTitle, $sectionRecord);

        $this->assertStringContainsString('<a href=', $output);
        $this->assertStringContainsString('Linked Page', $output);
    }

    public function testPageTitleColumnFormattingRendersEmForOrphan(): void
    {
        $section = Section::create();
        $section->Title = 'Orphan';
        $section->write();

        $records = $this->report->sourceRecords();
        $sectionRecord = $this->findRecordById($records, $section->ID);
        $this->assertNotNull($sectionRecord);

        $columns = $this->report->columns();
        $formatting = $columns['PageTitle']['formatting'];
        $output = $formatting($sectionRecord->PageTitle, $sectionRecord);

        $this->assertStringContainsString('<em>', $output);
        $this->assertStringContainsString('Orphaned', $output);
    }

    public function testTypeColumnFormattingReturnsElementType(): void
    {
        $page = $this->createPage('Type Test');

        $section = Section::create();
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        $records = $this->report->sourceRecords();
        $sectionRecord = $this->findRecordById($records, $section->ID);
        $this->assertNotNull($sectionRecord);

        $columns = $this->report->columns();
        $formatting = $columns['Type']['formatting'];
        $output = $formatting(null, $sectionRecord);

        $this->assertSame('Section', $output);
    }

    public function testFilterBySpecificPageExcludesOtherPages(): void
    {
        $pageA = $this->createPage('Page A Filter');
        $pageB = $this->createPage('Page B Filter');

        $sectionA = Section::create();
        $sectionA->ParentID = $pageA->ID;
        $sectionA->ParentClass = $pageA::class;
        $sectionA->write();

        $sectionB = Section::create();
        $sectionB->ParentID = $pageB->ID;
        $sectionB->ParentClass = $pageB::class;
        $sectionB->write();

        $records = $this->report->sourceRecords(['PageID' => (string) $pageA->ID]);

        // Count elements under page A (section + auto-scaffolded row + column)
        $count = 0;
        foreach ($records as $record) {
            $count++;
            $this->assertSame('Page A Filter', $record->PageTitle);
        }

        // Section + Row + Column = 3 auto-scaffolded elements
        $this->assertSame(3, $count);
    }

    private function createPage(string $title): TestPage
    {
        $page = TestPage::create();
        $page->Title = $title;
        $page->write();

        return $page;
    }

    /**
     * @param iterable<GridElement> $records
     */
    private function findRecordById(iterable $records, int $id): ?GridElement
    {
        foreach ($records as $record) {
            if ((int) $record->ID === $id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @param iterable<GridElement> $records
     * @param class-string $className
     */
    private function findRecordByClass(iterable $records, string $className): ?GridElement
    {
        foreach ($records as $record) {
            if ($record instanceof $className) {
                return $record;
            }
        }

        return null;
    }
}
