<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Reports;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Reports\GridElementReport;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(GridElementReport::class)]
final class GridElementReportTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    private function report(): GridElementReport
    {
        return GridElementReport::create();
    }

    public function testTitleReturnsNonEmptyString(): void
    {
        self::assertNotEmpty($this->report()->title());
    }

    public function testDescriptionReturnsNonEmptyString(): void
    {
        self::assertNotEmpty($this->report()->description());
    }

    public function testSourceRecordsReturnsAllElements(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::treeFor($page);

        $records = $this->report()->sourceRecords();

        // At least the 4 elements we created (section, row, column, content)
        self::assertGreaterThanOrEqual(4, $records->count());
    }

    public function testSourceRecordsFiltersByClassName(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::section($page);
        ['section' => $section2, 'row' => $row, 'column' => $column] = GridTreeFactory::containerTree($page);

        $records = $this->report()->sourceRecords(['ClassName' => Section::class]);

        $ids = array_map('intval', $records->column('ID'));

        // Only Section records survive the ClassName filter — the Row and Column
        // are excluded. Negating the `$classFilter !== null` guard (or dropping the
        // (string) cast that derives it) would skip the filter and leak them in.
        self::assertContains((int) $section2->ID, $ids);
        self::assertNotContains((int) $row->ID, $ids);
        self::assertNotContains((int) $column->ID, $ids);
        foreach ($records as $record) {
            self::assertInstanceOf(Section::class, $record);
        }
        self::assertGreaterThanOrEqual(2, $records->count());
    }

    public function testSourceRecordsFiltersByPageIdSkippingMismatches(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');

        // page1's section is created (and therefore iterated) first. Filtering to page2
        // means the very first element mismatches: it must be skipped, not stop the loop.
        // PageID is passed as string (from form submission).
        $section1 = GridTreeFactory::section($page1, 'main', 0, 'Page1 Section');
        $section2 = GridTreeFactory::section($page2, 'main', 0, 'Page2 Section');

        $records = $this->report()->sourceRecords(['PageID' => (string) $page2->ID]);
        $ids = array_map('intval', $records->column('ID'));

        // page2's section is present, page1's is absent. Dropping the `continue` on
        // the PageID mismatch (or flipping the `!==`) would leak page1's section in.
        self::assertContains((int) $section2->ID, $ids);
        self::assertNotContains((int) $section1->ID, $ids);

        // Every surviving record belongs to the filtered page.
        foreach ($records as $record) {
            $recordPage = $record->getPage();
            self::assertInstanceOf(SiteTree::class, $recordPage);
            self::assertSame((int) $page2->ID, (int) $recordPage->ID);
        }
    }

    public function testSourceRecordsFiltersOrphanedOnly(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column, 'content' => $normalElement] = GridTreeFactory::treeFor($page);

        // Create an orphan: write with valid parent, then orphan via raw SQL and reload
        $orphan = GridTreeFactory::contentElement($column);
        $orphanId = (int) $orphan->ID;
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "UPDATE \"%s\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $table,
            $orphanId,
        ));

        $records = $this->report()->sourceRecords(['PageID' => 'orphaned']);

        $ids = array_map('intval', $records->column('ID'));
        self::assertContains($orphanId, $ids);
        self::assertNotContains((int) $normalElement->ID, $ids);
    }

    /**
     * Extract the trail label sequence from a rendered Location cell. Crumbs render
     * as `<a class="grid-report__crumb" href="...">Label</a>` (or a `<span>` when
     * unlinked), so the capture is the escaped label text in document order.
     *
     * @return list<string>
     */
    private static function trailLabels(string $html): array
    {
        preg_match_all('/class="grid-report__crumb"[^>]*>([^<]*)</', $html, $matches);

        return $matches[1];
    }

    /**
     * Render one report cell for the given element by locating its record in
     * sourceRecords() and applying the named column's formatting callback.
     */
    private function cellHtmlFor(GridElement $element, string $column = 'Location'): string
    {
        $formatter = $this->report()->columns()[$column]['formatting'];

        foreach ($this->report()->sourceRecords() as $record) {
            if ((int) $record->ID === (int) $element->ID) {
                return $formatter(null, $record);
            }
        }

        self::fail('Element should appear in sourceRecords');
    }

    public function testLocationColumnRendersTrailSegmentsAsLinkedCrumbs(): void
    {
        // Wiring plus segment shape — the ancestor walk itself (ordering source,
        // collision guard) is covered structurally in GridTreeServiceTest. Here we
        // assert the report turns each segment into an anchor pointing at that
        // segment's edit URL.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Hero Section');
        $row = GridTreeFactory::row($section, 0, 'Top Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Intro Text');

        $html = $this->cellHtmlFor($content);

        self::assertStringContainsString(
            sprintf('href="%s"', htmlspecialchars((string) $page->getCMSEditLink(), ENT_QUOTES)),
            $html,
        );
        self::assertStringContainsString(
            sprintf('href="%s"', htmlspecialchars((string) $section->getCMSEditLink(), ENT_QUOTES)),
            $html,
        );
        self::assertStringContainsString(
            sprintf('href="%s"', htmlspecialchars((string) $column->getCMSEditLink(), ENT_QUOTES)),
            $html,
        );
        // The element's own level is not part of its trail.
        self::assertStringNotContainsString('Intro Text', $html);
    }

    public function testLocationTrailRootsAtPageFollowedByAncestors(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Hero Section');
        $row = GridTreeFactory::row($section, 0, 'Top Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Intro Text');

        $labels = self::trailLabels($this->cellHtmlFor($content));

        // Page root, then each ancestor outermost-first; the element itself omitted.
        self::assertSame(
            array_map(
                static fn (string $label): string => htmlspecialchars($label, ENT_QUOTES),
                [(string) $page->Title, 'Hero Section', 'Top Row', (string) $column->Title],
            ),
            $labels,
        );
    }

    public function testLocationTrailLabelFallsBackToTypeWhenTitleBlank(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Hero Section');
        $row = GridTreeFactory::row($section, 0, 'Top Row');
        $column = GridTreeFactory::column($row);
        $content = GridTreeFactory::contentElement($column, 0, 'Intro Text');

        // Blank the Row's stored title directly (bypassing the write-time default).
        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            'UPDATE "%s" SET "Title" = \'\' WHERE "ID" = %d',
            $table,
            (int) $row->ID,
        ));

        $labels = self::trailLabels($this->cellHtmlFor($content));

        // Row segment (index 2) falls back to the element type instead of an empty label.
        self::assertSame($row->getType(), $labels[2]);
    }

    public function testLocationColumnRendersOrphanWithoutLink(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['content' => $orphan] = GridTreeFactory::treeFor($page);

        $table = DataObject::getSchema()->tableName(GridElement::class);
        DB::query(sprintf(
            "UPDATE \"%s\" SET \"ParentID\" = 0, \"ParentClass\" = '' WHERE \"ID\" = %d",
            $table,
            (int) $orphan->ID,
        ));

        $html = $this->cellHtmlFor($orphan);

        self::assertStringContainsString('Orphaned', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function testColumnsReturnsExpectedKeys(): void
    {
        $columns = $this->report()->columns();

        self::assertArrayHasKey('Title', $columns);
        self::assertArrayHasKey('Type', $columns);
        self::assertArrayHasKey('Location', $columns);
        // Page and Last Edited were folded away: the page now lives in the trail
        // and recency is out of scope for a locator report.
        self::assertArrayNotHasKey('PageTitle', $columns);
        self::assertArrayNotHasKey('LastEdited', $columns);

        foreach ($columns as $column) {
            self::assertArrayHasKey('title', $column);
        }
    }

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

    public function testParameterFieldsTypeDropdownExcludesBaseClass(): void
    {
        $fields = $this->report()->parameterFields();
        /** @var DropdownField $typeField */
        $typeField = $fields->fieldByName('ClassName');
        $source = $typeField->getSource();

        self::assertArrayNotHasKey(GridElement::class, $source);
        // Should include concrete subclasses
        self::assertNotEmpty($source);
    }

    public function testSourceRecordsAcceptsAnIntegerPageIdParam(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');

        $section1 = GridTreeFactory::section($page1, 'main', 0, 'Page1 Section');
        $section2 = GridTreeFactory::section($page2, 'main', 0, 'Page2 Section');

        // An int PageID must be coerced and honoured, not discarded as "not a string".
        $records = $this->report()->sourceRecords(['PageID' => (int) $page1->ID]);
        $ids = array_map('intval', $records->column('ID'));

        self::assertContains((int) $section1->ID, $ids);
        self::assertNotContains((int) $section2->ID, $ids);
    }

    public function testSourceRecordsIgnoresANonScalarPageIdParam(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $section1 = GridTreeFactory::section($page1, 'main', 0, 'Page1 Section');

        // Neither string nor int: the param degrades to "no filter" rather than
        // being stringified into a filter value that matches nothing.
        $records = $this->report()->sourceRecords(['PageID' => ['not', 'scalar']]);
        $ids = array_map('intval', $records->column('ID'));

        self::assertContains((int) $section1->ID, $ids);
    }

    public function testTitleColumnRendersAPlainLabelWhenTheElementHasNoEditLink(): void
    {
        // An element whose parent record no longer exists has no CMS edit link, so the
        // Title cell must be the bare label — not an anchor with an empty href.
        $orphan = Section::create();
        $orphan->Title = 'Orphan Section';
        $orphan->ParentID = 987654;
        $orphan->ParentClass = Page::class;
        $orphan->write();

        self::assertNull($orphan->getCMSEditLink());

        $titleFormatter = $this->report()->columns()['Title']['formatting'];

        self::assertSame('Orphan Section', $titleFormatter(null, $orphan));
    }

    public function testColumnsFormattingCallbacksReturnStrings(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, 'main', 0, 'Formatted Section');

        // Title formatter — links the element to its own CMS edit URL.
        $titleResult = $this->cellHtmlFor($section, 'Title');
        self::assertStringContainsString('Formatted Section', $titleResult);
        self::assertStringContainsString((string) $section->getCMSEditLink(), $titleResult);

        // Type formatter
        self::assertNotEmpty($this->cellHtmlFor($section, 'Type'));

        // Location formatter — passes through the enriched trail.
        self::assertStringContainsString((string) $page->Title, $this->cellHtmlFor($section));
    }
}
