<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RejectOnReindexExtension;
use WeDevelop\Grid\Validation\ReorderValidator;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ElementPlacementService::class)]
final class ElementPlacementServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /**
     * Abstains for every element except the specific title/sort pair the rollback
     * test sets up, so the rest of this class is unaffected.
     *
     * @var array<class-string, list<class-string>>
     */
    protected static $required_extensions = [
        GridElement::class => [RejectOnReindexExtension::class],
    ];

    private ElementPlacementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(ElementPlacementService::class);
    }

    public function testCrossParentMoveReindexesEveryTargetSiblingNotJustTheMovedElement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sourceSection = GridTreeFactory::section($page);
        $targetSection = GridTreeFactory::section($page);

        $moved = GridTreeFactory::row($sourceSection, sort: 1);
        $existingFirst = GridTreeFactory::row($targetSection, sort: 1);
        $existingSecond = GridTreeFactory::row($targetSection, sort: 2);

        // Insert at the front of the target: every existing target sibling shifts down
        // and must be persisted, not only the moved element.
        $result = $this->service->reorder($moved, $targetSection, null);

        self::assertTrue($result->isOk());
        self::assertSame(1, (int) Row::get()->byID((int) $moved->ID)->Sort);
        self::assertSame(2, (int) Row::get()->byID((int) $existingFirst->ID)->Sort);
        self::assertSame(3, (int) Row::get()->byID((int) $existingSecond->ID)->Sort);
    }

    public function testReorderRollsBackTheWholeBatchWhenALaterSiblingWriteFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $first = GridTreeFactory::section($page, sort: 1, title: 'first');
        GridTreeFactory::section($page, sort: 2, title: RejectOnReindexExtension::REJECTED_TITLE);
        $last = GridTreeFactory::section($page, sort: 3, title: 'last');

        // Moving $last to the front reindexes to [last=1, first=2, rejected=3]. The
        // rejected element is written last, after two successful writes — so without a
        // transaction those two writes would survive the failure.
        $result = $this->service->reorder($last, $page, null);

        self::assertTrue($result->isErr());
        self::assertSame(3, (int) Section::get()->byID((int) $last->ID)->Sort, 'the failed batch must roll back');
        self::assertSame(1, (int) Section::get()->byID((int) $first->ID)->Sort);
    }

    public function testSameParentMoveToFront(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        $result = $this->service->reorder($row3, $section, null);

        self::assertTrue($result->isOk());

        // Reload from DB to check persisted Sort values
        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row3->Sort);
        self::assertSame(2, $row1->Sort);
        self::assertSame(3, $row2->Sort);
    }

    public function testSameParentMoveToMiddle(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        // Move row1 after row2
        $result = $this->service->reorder($row1, $section, $row2->ID);

        self::assertTrue($result->isOk());

        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row2->Sort);
        self::assertSame(2, $row1->Sort);
        self::assertSame(3, $row3->Sort);
    }

    public function testSameParentMoveToEnd(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        // Move row1 after row3
        $result = $this->service->reorder($row1, $section, $row3->ID);

        self::assertTrue($result->isOk());

        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row2->Sort);
        self::assertSame(2, $row3->Sort);
        self::assertSame(3, $row1->Sort);
    }

    public function testCrossParentMove(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $rowA = GridTreeFactory::row($sectionA);
        GridTreeFactory::row($sectionB);

        // Move rowA into sectionB at the front
        $result = $this->service->reorder($rowA, $sectionB, null);

        self::assertTrue($result->isOk());

        $rowA = GridElement::get()->byID($rowA->ID);

        self::assertSame((int) $sectionB->ID, $rowA->ParentID);
        self::assertSame(Section::class, $rowA->ParentClass);
    }

    public function testCrossParentMoveSourceGapsClosed(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($sectionA);
        $row2 = GridTreeFactory::row($sectionA);
        $row3 = GridTreeFactory::row($sectionA);

        // Move row2 out of sectionA
        $result = $this->service->reorder($row2, $sectionB, null);

        self::assertTrue($result->isOk());

        // Source section's remaining rows should have contiguous Sort values
        $row1 = GridElement::get()->byID($row1->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row1->Sort);
        self::assertSame(2, $row3->Sort);
    }

    public function testZoneScopedReorderForSections(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $main1 = GridTreeFactory::section($page, zone: 'main');
        $main2 = GridTreeFactory::section($page, zone: 'main');
        $sidebar1 = GridTreeFactory::section($page, zone: 'sidebar');
        $sidebar2 = GridTreeFactory::section($page, zone: 'sidebar');

        // Reorder within 'main' zone: move main1 after main2
        $result = $this->service->reorder($main1, $page, $main2->ID);

        self::assertTrue($result->isOk());

        // Sidebar zone sections should be untouched
        $sidebar1 = GridElement::get()->byID($sidebar1->ID);
        $sidebar2 = GridElement::get()->byID($sidebar2->ID);

        self::assertSame(1, $sidebar1->Sort);
        self::assertSame(2, $sidebar2->Sort);
    }

    public function testInvalidReferenceElementReturnsFail(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Use an ID that does not exist in the target parent
        $result = $this->service->reorder($row, $section, 999999);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testValidationFailureReturnsFail(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $originalSort = $row->Sort;

        // Row cannot be placed at page level — validator should reject
        $result = $this->service->reorder($row, $page, null);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());

        // Element should not have been modified
        $reloaded = GridElement::get()->byID($row->ID);
        self::assertSame($originalSort, $reloaded->Sort);
    }

    public function testCrossParentSectionMoveWithZoneFiltering(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');

        // main zone: 1, 2 (moved away), 3 — removing #2 leaves a gap that the
        // source reindex must close (main3: 3 → 2).
        $main1 = GridTreeFactory::section($page1, zone: 'main', sort: 1);
        $main2 = GridTreeFactory::section($page1, zone: 'main', sort: 2);
        $main3 = GridTreeFactory::section($page1, zone: 'main', sort: 3);
        // sidebar zone: an independent Sort space the source reindex must NOT
        // touch. If L110 stopped zone-filtering the source siblings, sidebar1
        // would be folded into the main reindex and its Sort would change.
        $sidebar1 = GridTreeFactory::section($page1, zone: 'sidebar', sort: 1);
        $sidebar2 = GridTreeFactory::section($page1, zone: 'sidebar', sort: 2);

        // Move main2 from page1 to page2
        $result = $this->service->reorder($main2, $page2, null);

        self::assertTrue($result->isOk());

        // Source main zone: gap closed, contiguous 1..2
        $main1 = GridElement::get()->byID($main1->ID);
        $main3 = GridElement::get()->byID($main3->ID);
        self::assertSame(1, $main1->Sort, 'main1 sort should remain 1');
        self::assertSame(2, $main3->Sort, 'main3 should slide down to close the gap left by main2');

        // Sidebar zone untouched — different zone is excluded from the source reindex
        $sidebar1 = GridElement::get()->byID($sidebar1->ID);
        $sidebar2 = GridElement::get()->byID($sidebar2->ID);
        self::assertSame(1, $sidebar1->Sort, 'sidebar1 sort should remain 1 (different zone)');
        self::assertSame(2, $sidebar2->Sort, 'sidebar2 sort should remain 2 (different zone)');
    }

    public function testInvalidReferenceErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Use an ID that does not exist in the target parent — triggers AFTER_ELEMENT_NOT_FOUND
        $result = $this->service->reorder($row, $section, 999999);

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(ElementPlacementService::class . '.AFTER_ELEMENT_NOT_FOUND', $error->key);
    }

    public function testWriteFailureDuringPersistPropagatesAsError(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $col1 = GridTreeFactory::column($row);
        $col2 = GridTreeFactory::column($row);

        // Set invalid GridSettings on col1 in memory (width 13 exceeds 12-column grid)
        // Do NOT write — the invalid state only exists in memory
        $col1->setGridSettings(new GridSettings(
            new ViewportConfig(13, 0, true),
            [],
        ));

        // Reorder col1 after col2 — this triggers a write() on col1 with invalid settings
        $result = $this->service->reorder($col1, $row, $col2->ID);

        self::assertTrue($result->isErr(), 'Reorder should fail when element write triggers validation error');
    }

    // NOTE: ElementPlacementService::persistAndReturn() contains a defensive
    // `if (DB::get_conn() === null)` arm that writes the dirty elements without
    // wrapping them in a transaction. That branch is intentionally left
    // untested here: a live SapphireTest always has a real database connection,
    // and forcing DB::get_conn() to null at runtime would break the framework
    // state that every other test in this class depends on (and the subsequent
    // write() calls themselves). The branch is a belt-and-braces guard for
    // environments without a connection; the transactional happy path is
    // exercised by every persisting test above.

    public function testInsertAfterBumpsSort(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $a = GridTreeFactory::contentElement($column, title: 'A');
        $b = GridTreeFactory::contentElement($column, title: 'B');
        $c = GridTreeFactory::contentElement($column, title: 'C');

        $inserted = ContentElement::create();
        $inserted->Title = 'Inserted';
        $inserted->ParentID = $column->ID;
        $inserted->ParentClass = $column::class;
        $inserted->write();

        $result = $this->service->insertAfter($inserted, $column, (int) $a->ID);

        self::assertTrue($result->isOk());

        $a = ContentElement::get()->byID($a->ID);
        $b = ContentElement::get()->byID($b->ID);
        $c = ContentElement::get()->byID($c->ID);
        $inserted = ContentElement::get()->byID($inserted->ID);

        self::assertSame(1, $a->Sort);
        self::assertSame(2, $inserted->Sort);
        self::assertSame(3, $b->Sort);
        self::assertSame(4, $c->Sort);
        self::assertSame('Inserted', $inserted->Title);
    }

    public function testInsertAfterNonexistentReferenceFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $element = GridTreeFactory::contentElement($column);

        $result = $this->service->insertAfter($element, $column, 999999);

        self::assertTrue($result->isErr());
        $error = $result->errors()[0];
        self::assertSame(ElementPlacementService::class . '.AFTER_ELEMENT_NOT_FOUND', $error->key);
    }

    public function testInsertAfterBumpsOnlySameParentSiblings(): void
    {
        // Polymorphic parent-ID isolation: bumping siblings in Column A must
        // not touch elements in Column B even though both share the same
        // ParentClass. Removing the ParentClass filter from the sibling
        // query would bump B's Sort too — this test pins that against
        // regression.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $columnA = GridTreeFactory::column($row);
        $columnB = GridTreeFactory::column($row);

        $a1 = GridTreeFactory::contentElement($columnA, title: 'A1');
        $a2 = GridTreeFactory::contentElement($columnA, title: 'A2');

        $b1 = GridTreeFactory::contentElement($columnB, title: 'B1');
        $b2 = GridTreeFactory::contentElement($columnB, title: 'B2');
        $originalB1Sort = (int) $b1->Sort;
        $originalB2Sort = (int) $b2->Sort;

        $inserted = ContentElement::create();
        $inserted->Title = 'Inserted-A';
        $inserted->ParentID = $columnA->ID;
        $inserted->ParentClass = $columnA::class;
        $inserted->write();

        $result = $this->service->insertAfter($inserted, $columnA, (int) $a1->ID);
        self::assertTrue($result->isOk());

        $a2 = ContentElement::get()->byID($a2->ID);
        $b1 = ContentElement::get()->byID($b1->ID);
        $b2 = ContentElement::get()->byID($b2->ID);

        self::assertSame(3, (int) $a2->Sort, 'Column A sibling must be bumped');
        self::assertSame($originalB1Sort, (int) $b1->Sort, 'Column B sibling must NOT be bumped');
        self::assertSame($originalB2Sort, (int) $b2->Sort, 'Column B sibling must NOT be bumped');
    }

    public function testInsertAfterRejectsDisallowedChildType(): void
    {
        // insertAfter must route through the validator so disallowed
        // cross-parent placements never persist on the new-element path.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // A validly-placed Row under its Section (so write() succeeds), then
        // attempt to place it under a Column — disallowed by ContainerType.
        $validRow = Row::create();
        $validRow->ParentID = $section->ID;
        $validRow->ParentClass = $section::class;
        $validRow->write();

        $result = $this->service->insertAfter($validRow, $column, null);

        self::assertTrue($result->isErr());
        $error = $result->errors()[0];
        self::assertSame(ReorderValidator::class . '.PARENT_REJECTED', $error->key);
    }

    // --- Mixed root siblings ----------------------------------------------
    //
    // A page zone holds TWO root classes: Sections and shared-block placements.
    // Scoping the sibling list to Sections dropped placements out of it, so a
    // move anchored on one failed outright, and a reindex renumbered only the
    // Sections and collided with the placement's Sort.

    /** @return array{page: Page, block: \WeDevelop\Grid\Model\SharedBlock} */
    private function pageWithBlock(): array
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');

        return ['page' => $page, 'block' => $block];
    }

    public function testSectionCanBeMovedAfterASharedBlockPlacement(): void
    {
        ['page' => $page, 'block' => $block] = $this->pageWithBlock();

        $first = GridTreeFactory::section($page, sort: 1);
        $placement = GridTreeFactory::reference($page, $block, sort: 2);
        $moved = GridTreeFactory::section($page, sort: 3);

        $result = $this->service->reorder($moved, $page, (int) $placement->ID);

        self::assertTrue($result->isOk(), implode(' ', array_map(
            static fn ($e): string => $e->message,
            $result->errors(),
        )));
        self::assertSame(1, (int) Section::get()->byID((int) $first->ID)->Sort);
        self::assertSame(2, (int) SharedBlockReference::get()->byID((int) $placement->ID)->Sort);
        self::assertSame(3, (int) Section::get()->byID((int) $moved->ID)->Sort);
    }

    public function testPlacementCanBeMovedAfterASection(): void
    {
        ['page' => $page, 'block' => $block] = $this->pageWithBlock();

        $first = GridTreeFactory::section($page, sort: 1);
        $second = GridTreeFactory::section($page, sort: 2);
        $placement = GridTreeFactory::reference($page, $block, sort: 3);

        $result = $this->service->reorder($placement, $page, (int) $first->ID);

        self::assertTrue($result->isOk());
        self::assertSame(1, (int) Section::get()->byID((int) $first->ID)->Sort);
        self::assertSame(2, (int) SharedBlockReference::get()->byID((int) $placement->ID)->Sort);
        self::assertSame(3, (int) Section::get()->byID((int) $second->ID)->Sort);
    }

    public function testInsertingAtTheStartRenumbersPlacementsToo(): void
    {
        // The silent half of the bug: reindexing only the Sections left the
        // placement on its old Sort, so the saved order differed from the one
        // the editor showed.
        ['page' => $page, 'block' => $block] = $this->pageWithBlock();

        $placement = GridTreeFactory::reference($page, $block, sort: 1);
        $existing = GridTreeFactory::section($page, sort: 2);
        $inserted = GridTreeFactory::section($page, sort: 3);

        $result = $this->service->reorder($inserted, $page, null);

        self::assertTrue($result->isOk());
        self::assertSame(1, (int) Section::get()->byID((int) $inserted->ID)->Sort);
        self::assertSame(2, (int) SharedBlockReference::get()->byID((int) $placement->ID)->Sort);
        self::assertSame(3, (int) Section::get()->byID((int) $existing->ID)->Sort);
    }

    public function testZonesStayIndependentWhenPlacementsAreInvolved(): void
    {
        // The zone scoping the old class filter also provided must survive:
        // a sidebar placement is not a sibling of a main-zone section.
        ['page' => $page, 'block' => $block] = $this->pageWithBlock();

        $sidebar = GridTreeFactory::reference($page, $block, zone: 'sidebar', sort: 1);
        $mainFirst = GridTreeFactory::section($page, zone: 'main', sort: 1);
        $mainSecond = GridTreeFactory::section($page, zone: 'main', sort: 2);

        $result = $this->service->reorder($mainSecond, $page, null);

        self::assertTrue($result->isOk());
        self::assertSame(1, (int) Section::get()->byID((int) $mainSecond->ID)->Sort);
        self::assertSame(2, (int) Section::get()->byID((int) $mainFirst->ID)->Sort);
        self::assertSame(
            1,
            (int) SharedBlockReference::get()->byID((int) $sidebar->ID)->Sort,
            'the sidebar zone keeps its own sequence',
        );
    }

    public function testNewSectionTakesASortPastAPlacementInTheSameZone(): void
    {
        // ensureSortSet(): a zone's max Sort spans both root classes, so a new
        // section written behind a placement must not reuse its Sort.
        ['page' => $page, 'block' => $block] = $this->pageWithBlock();

        GridTreeFactory::reference($page, $block, sort: 7);

        $section = Section::create();
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        self::assertSame(8, (int) $section->Sort);
    }
}
