<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Service\ElementPlacementService;
use WeDevelop\Grid\Service\GridElementService;
use WeDevelop\Grid\Tests\Integration\Support\AlwaysFailingReorderValidator;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Validation\ReorderValidator;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(GridElementService::class)]
final class GridElementServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridElementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(GridElementService::class);
    }

    public function testCreateSectionUnderPage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $result = $this->service->createElement($page, ContainerType::Section, 'main', null);

        self::assertTrue($result->isOk());

        $section = $result->unwrap();
        self::assertInstanceOf(Section::class, $section);
        self::assertSame((int) $page->ID, $section->ParentID);
        self::assertSame(Page::class, $section->ParentClass);
        self::assertSame('main', $section->Zone);
    }

    public function testCreateRowUnderSection(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $result = $this->service->createElement($section, ContainerType::Row, 'main', null);

        self::assertTrue($result->isOk());

        $row = $result->unwrap();
        self::assertInstanceOf(Row::class, $row);
        self::assertSame((int) $section->ID, $row->ParentID);
        self::assertSame(Section::class, $row->ParentClass);
    }

    public function testCreateElementInsertAfterSibling(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);

        $result = $this->service->createElement($section, ContainerType::Row, 'main', $row1->ID);

        self::assertTrue($result->isOk());

        $newRow = $result->unwrap();

        // Reload to verify persisted sort order
        $row1 = GridElement::get()->byID($row1->ID);
        $newRow = GridElement::get()->byID($newRow->ID);
        $row2 = GridElement::get()->byID($row2->ID);

        self::assertGreaterThan($row1->Sort, $newRow->Sort);
        self::assertGreaterThan($newRow->Sort, $row2->Sort);
    }

    public function testCreateElementWithoutReferenceAppendsAfterExistingSiblings(): void
    {
        // afterElementId === null short-circuits in writeThenPlace and keeps the
        // appended Sort assigned by ensureSortSet() — the new row lands at the
        // END of the sibling list. The ReturnRemoval mutant on that early return
        // would fall through to insertAfter(..., null), which splices at index 0
        // and prepends the row instead.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $existing1 = GridTreeFactory::row($section, title: 'Row 1');
        $existing2 = GridTreeFactory::row($section, title: 'Row 2');

        $result = $this->service->createElement($section, ContainerType::Row, 'main', null);

        self::assertTrue($result->isOk());

        $newRow = GridElement::get()->byID($result->unwrap()->ID);
        $existing1 = GridElement::get()->byID($existing1->ID);
        $existing2 = GridElement::get()->byID($existing2->ID);

        self::assertGreaterThan($existing1->Sort, $newRow->Sort, 'New row appends after the first sibling');
        self::assertGreaterThan($existing2->Sort, $newRow->Sort, 'New row appends after the last sibling');
    }

    public function testCreateElementInsertAtStart(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);

        $result = $this->service->createElement($section, ContainerType::Row, 'main', null, insertAtStart: true);

        self::assertTrue($result->isOk());

        $newRow = $result->unwrap();

        // Reload to verify persisted sort order
        $row1 = GridElement::get()->byID($row1->ID);
        $newRow = GridElement::get()->byID($newRow->ID);
        $row2 = GridElement::get()->byID($row2->ID);

        self::assertLessThan($row1->Sort, $newRow->Sort);
        self::assertLessThan($row2->Sort, $row1->Sort);
    }

    public function testCreateContentElementUnderColumn(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $result = $this->service->createContentElement($column, ContentElement::class, null);

        self::assertTrue($result->isOk());

        $element = $result->unwrap();
        self::assertInstanceOf(ContentElement::class, $element);
        self::assertSame((int) $column->ID, $element->ParentID);
        self::assertSame(Column::class, $element->ParentClass);
    }

    public function testCreateContentElementInsertAfterSibling(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $existing1 = GridTreeFactory::contentElement($column, title: 'First');
        $existing2 = GridTreeFactory::contentElement($column, title: 'Second');

        $result = $this->service->createContentElement($column, ContentElement::class, $existing1->ID);

        self::assertTrue($result->isOk());

        $newElement = $result->unwrap();

        $existing1 = GridElement::get()->byID($existing1->ID);
        $newElement = GridElement::get()->byID($newElement->ID);
        $existing2 = GridElement::get()->byID($existing2->ID);

        self::assertGreaterThan($existing1->Sort, $newElement->Sort);
        self::assertGreaterThan($newElement->Sort, $existing2->Sort);
    }

    public function testDuplicateElementCreatesShallowCopyWithCopyTitle(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, title: 'My Section');

        $result = $this->service->duplicateElement($section);

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertSame('My Section copy', $clone->Title);
        self::assertSame($section->ParentID, $clone->ParentID);
        self::assertSame($section->ParentClass, $clone->ParentClass);
        self::assertNotSame((int) $section->ID, (int) $clone->ID);
    }

    public function testDuplicateElementInsertsAfterOriginal(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section, title: 'Row 1');
        $row2 = GridTreeFactory::row($section, title: 'Row 2');

        $result = $this->service->duplicateElement($row1);

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();

        $row1 = GridElement::get()->byID($row1->ID);
        $clone = GridElement::get()->byID($clone->ID);
        $row2 = GridElement::get()->byID($row2->ID);

        self::assertGreaterThan($row1->Sort, $clone->Sort);
        self::assertGreaterThan($clone->Sort, $row2->Sort);
    }

    public function testDuplicateElementToAnotherPage(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($page1, title: 'Original');

        $result = $this->service->duplicateElementTo(
            $section,
            $page2,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertInstanceOf(Section::class, $clone);
        self::assertSame('Original copy', $clone->Title);
        self::assertSame((int) $page2->ID, $clone->ParentID);
        self::assertSame('main', $clone->Zone);
        self::assertNotSame((int) $section->ID, (int) $clone->ID);
    }

    public function testDuplicateSectionToDifferentZoneRezonesClone(): void
    {
        // duplicateElementTo re-zones a Section to the target zone via the
        // `if ($clone instanceof Section)` guard. Source zone ('main') must
        // DIFFER from the target zone ('sidebar') so the InstanceOf_ mutant
        // (which would skip the rezone and leave the copied 'main') is killed.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main', title: 'Main Section');

        $result = $this->service->duplicateElementTo(
            $section,
            $page,
            (int) $page->ID,
            'sidebar',
        );

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertInstanceOf(Section::class, $clone);
        self::assertSame('sidebar', $clone->Zone, 'Clone must be rezoned to the target zone, not keep the source zone');
    }

    public function testDuplicateRowToAnotherSection(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($sectionA, title: 'Original Row');

        $result = $this->service->duplicateElementTo(
            $row,
            $sectionB,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertInstanceOf(Row::class, $clone);
        self::assertSame('Original Row copy', $clone->Title);
        self::assertSame((int) $sectionB->ID, $clone->ParentID);
        self::assertSame(Section::class, $clone->ParentClass);
    }

    public function testDuplicateElementToFailsWhenTargetParentNotOnClaimedPage(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');

        $sectionOnPage1 = GridTreeFactory::section($page1);
        $rowOnPage1 = GridTreeFactory::row($sectionOnPage1, title: 'Row');

        $result = $this->service->duplicateElementTo(
            $rowOnPage1,
            $sectionOnPage1,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenTargetParentNotInClaimedZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $row = GridTreeFactory::row($section, title: 'Row');

        $result = $this->service->duplicateElementTo(
            $row,
            $section,
            (int) $page->ID,
            'sidebar',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenHierarchyViolated(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $rowToCopy = GridTreeFactory::row($section, title: 'Rogue Row');

        $result = $this->service->duplicateElementTo(
            $rowToCopy,
            $column,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testDuplicateElementToFailsWhenSectionTargetParentMismatchesPageId(): void
    {
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($page1, title: 'Section');

        $result = $this->service->duplicateElementTo(
            $section,
            $page1,
            (int) $page2->ID,
            'main',
        );

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testCreateElementRollsBackWriteWhenPlacementFails(): void
    {
        // If the requested afterElementID does not exist, placement returns
        // an error Result — the element's write must roll back so the
        // caller never observes a persisted element paired with a failure.
        $page = $this->objFromFixture(Page::class, 'test_page');

        $countBefore = Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class])->count();

        $result = $this->service->createElement($page, ContainerType::Section, 'main', 999999);

        self::assertTrue($result->isErr(), 'createElement with a nonexistent afterElementID must fail');

        $countAfter = Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class])->count();
        self::assertSame(
            $countBefore,
            $countAfter,
            'failed placement must roll back the write — no new Section should exist',
        );
    }

    public function testCreateElementWithInsertAtStartRollsBackWriteWhenPlacementFails(): void
    {
        // Parallel coverage for the insertAtStart branch of writeAndPlace.
        // Unlike the afterElementID=999999 case, insertAtStart routes through
        // insertAfter($element, $parent, null) — null short-circuits in
        // resolveInsertionIndex (returns 0), so there is no natural placement
        // failure to provoke without substituting a collaborator.
        //
        // The hierarchy validator is identical at write time and placement
        // time (shared ElementAllowanceTrait), so a hierarchy violation would
        // be rejected by the write before placement is reached.
        //
        // Construct the service chain by hand with a TestOnly validator that
        // always fails — that lets placement return an err Result, which the
        // writeAndPlace transaction wrapper must still roll back. Wiring is
        // manual rather than Injector-driven because ElementPlacementService
        // is a class-bound singleton: a `registerService` on the validator
        // alone would not propagate to the already-cached placement service.
        $failingValidator = new AlwaysFailingReorderValidator();
        $placement = new ElementPlacementService(
            $failingValidator,
            Injector::inst()->get(GridElementRepositoryInterface::class),
        );
        $service = new GridElementService($failingValidator, $placement);

        $page = $this->objFromFixture(Page::class, 'test_page');

        $countBefore = Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class])->count();

        $result = $service->createElement($page, ContainerType::Section, 'main', null, insertAtStart: true);

        self::assertTrue(
            $result->isErr(),
            'createElement insertAtStart with a failing placement validator must fail',
        );

        $countAfter = Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class])->count();
        self::assertSame(
            $countBefore,
            $countAfter,
            'failed placement on the insertAtStart branch must roll back the write — no new Section should exist',
        );
    }

    public function testViolationErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $rowToCopy = GridTreeFactory::row($section, title: 'Rogue Row');

        // Row inside Column violates hierarchy — rejected by ReorderValidator
        $result = $this->service->duplicateElementTo(
            $rowToCopy,
            $column,
            (int) $page->ID,
            'main',
        );

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(ReorderValidator::class . '.PARENT_REJECTED', $error->key);
        self::assertArrayHasKey('element', $error->params);
        self::assertArrayHasKey('parent', $error->params);
    }

    public function testCreateElementPersistsToDatabaseWithoutInsertAfterSibling(): void
    {
        // Without insertAfterElementID, the outer write() is the only path that
        // persists the new element. Pins the MethodCallRemoval at line 52.
        $page = $this->objFromFixture(Page::class, 'test_page');

        $result = $this->service->createElement($page, ContainerType::Section, 'main', null);
        self::assertTrue($result->isOk());

        $section = $result->unwrap();
        self::assertGreaterThan(0, (int) $section->ID, 'write() must assign an ID');
        self::assertInstanceOf(Section::class, Section::get()->byID((int) $section->ID));
    }

    public function testCreateContentElementPersistsToDatabaseWithoutInsertAfterSibling(): void
    {
        // Same as above for createContentElement — pins MethodCallRemoval at line 78.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $result = $this->service->createContentElement($column, ContentElement::class, null);
        self::assertTrue($result->isOk());

        $element = $result->unwrap();
        self::assertGreaterThan(0, (int) $element->ID);
        self::assertInstanceOf(ContentElement::class, ContentElement::get()->byID((int) $element->ID));
    }

    public function testDuplicateElementToPersistsCloneToDatabase(): void
    {
        // duplicateElementTo's WriteResult just calls `$clone->write();` with no
        // placement fallback. Pins MethodCallRemoval on the inner write().
        $page1 = $this->objFromFixture(Page::class, 'test_page');
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');
        $section = GridTreeFactory::section($page1, title: 'Persisted Section');

        $result = $this->service->duplicateElementTo($section, $page2, (int) $page2->ID, 'main');
        self::assertTrue($result->isOk());

        $clone = $result->unwrap();
        self::assertGreaterThan(0, (int) $clone->ID);
        self::assertInstanceOf(Section::class, Section::get()->byID((int) $clone->ID));
    }

    public function testDuplicateElementToColumnResolvesZoneThroughAncestorChain(): void
    {
        // Row's target parent is a Column inside a Row inside a 'sidebar' Section.
        // validateOwnership walks ancestors up to the Section and compares Zone.
        // Pins the InstanceOf_ / While_ / Ternary mutants at lines 222-224.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar');
        $row = GridTreeFactory::row($sidebarSection);
        $column = GridTreeFactory::column($row);

        $elementToMove = GridTreeFactory::contentElement($column, title: 'Mover');

        // Correct zone → succeeds (proves the walk reaches the Section)
        $ok = $this->service->duplicateElementTo($elementToMove, $column, (int) $page->ID, 'sidebar');
        self::assertTrue($ok->isOk());

        // Wrong zone → ownership rejection (proves the Zone comparison runs)
        $mismatch = $this->service->duplicateElementTo($elementToMove, $column, (int) $page->ID, 'main');
        self::assertTrue($mismatch->isErr());
    }
}
