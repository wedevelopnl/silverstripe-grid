<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Repository;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Repository\OrmGridElementRepository;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(OrmGridElementRepository::class)]
final class OrmGridElementRepositoryTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private OrmGridElementRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        $this->repository = new OrmGridElementRepository();
    }

    public function testFindByIdReturnsElement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $found = $this->repository->findById((int) $section->ID);

        self::assertInstanceOf(GridElement::class, $found);
        self::assertSame((int) $section->ID, (int) $found->ID);
    }

    public function testFindByIdReturnsNullForMissing(): void
    {
        $found = $this->repository->findById(999999);

        self::assertNull($found);
    }

    public function testFindByParentIdsSorted(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $sectionA = GridTreeFactory::section($page, sort: 3);
        $sectionB = GridTreeFactory::section($page, sort: 1);
        $sectionC = GridTreeFactory::section($page, sort: 2);

        $results = $this->repository->findByParentIds(
            [(int) $page->ID],
            Page::class,
        );

        self::assertCount(3, $results);
        self::assertSame((int) $sectionB->ID, (int) $results[0]->ID, 'Sort 1 should be first');
        self::assertSame((int) $sectionC->ID, (int) $results[1]->ID, 'Sort 2 should be second');
        self::assertSame((int) $sectionA->ID, (int) $results[2]->ID, 'Sort 3 should be third');
    }

    public function testFindByParentIdsEmptyArray(): void
    {
        $results = $this->repository->findByParentIds([], Page::class);

        self::assertSame([], $results);
    }

    public function testFindByParentIdsMatchesParentIdAndParentClassAsAPair(): void
    {
        // Page IDs and element IDs share no namespace, so the same numeric ParentID
        // can legitimately refer to a page or to an element. Manufacture that
        // collision on a synthetic ID rather than hoping two auto-increments align:
        // both records below hang off ParentID 987654, differing only in ParentClass.
        $parentId = 987654;

        $sectionOfPage = Section::create();
        $sectionOfPage->ParentID = $parentId;
        $sectionOfPage->ParentClass = Page::class;
        $sectionOfPage->write();

        $rowOfSection = Row::create();
        $rowOfSection->ParentID = $parentId;
        $rowOfSection->ParentClass = Section::class;
        $rowOfSection->write();

        // A second page-parented section under a different ParentID: without it, a query
        // that dropped the ParentID filter would still return exactly one record.
        $sectionOfOtherPage = Section::create();
        $sectionOfOtherPage->ParentID = $parentId + 1;
        $sectionOfOtherPage->ParentClass = Page::class;
        $sectionOfOtherPage->write();

        // Each query must match the (ParentID, ParentClass) pair, never one alone.
        $pageChildren = $this->repository->findByParentIds([$parentId], Page::class);
        self::assertCount(1, $pageChildren);
        self::assertSame((int) $sectionOfPage->ID, (int) $pageChildren[0]->ID);

        $sectionChildren = $this->repository->findByParentIds([$parentId], Section::class);
        self::assertCount(1, $sectionChildren);
        self::assertSame((int) $rowOfSection->ID, (int) $sectionChildren[0]->ID);
    }

    public function testFindByParentsWithZoneFilter(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $mainSection = GridTreeFactory::section($page, zone: 'main');
        GridTreeFactory::section($page, zone: 'sidebar');

        $results = $this->repository->findByParents(
            [Page::class => [(int) $page->ID]],
            'main',
        );

        self::assertCount(1, $results);
        self::assertSame((int) $mainSection->ID, (int) $results[0]->ID);
    }

    public function testFindByParentsWithoutZoneFilter(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['section' => $section, 'row' => $row, 'column' => $column] = GridTreeFactory::containerTree($page);

        // Without zone, queries GridElement::get() — returns all element types
        $results = $this->repository->findByParents(
            [Section::class => [(int) $section->ID]],
        );

        self::assertCount(1, $results);
        self::assertSame((int) $row->ID, (int) $results[0]->ID);

        $results = $this->repository->findByParents(
            [Row::class => [(int) $row->ID]],
        );

        self::assertCount(1, $results);
        self::assertSame((int) $column->ID, (int) $results[0]->ID);
    }

    public function testFindByParentsIgnoresZoneForNonPageParentClass(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $row = GridTreeFactory::row($section);

        // A zone only filters root-level sections (page parents). When a zone is
        // passed alongside a non-page parent class it must be ignored: the query
        // stays on GridElement::get() with no Zone filter. The old `$zone !== null`
        // branch would have swapped to Section::get() + a Zone filter, matching no
        // rows (a Row's parent is a Section, not a page) and returning nothing.
        $results = $this->repository->findByParents(
            [Section::class => [(int) $section->ID]],
            'sidebar',
        );

        self::assertCount(1, $results);
        self::assertSame((int) $row->ID, (int) $results[0]->ID);
    }

    /**
     * Pins the `usort` comparator (spaceship over the [Sort, ID] tuple):
     *   - Spaceship direction (ascending): Sort=1 rows precede the Sort=2 section
     *     even though the Page-parented section is queried first — without the
     *     usort call, results come back in class-insertion order.
     *   - Tuple shape [Sort, ID]: the equal-Sort rows force the ID tiebreaker,
     *     so dropping either tuple field (or reversing the Spaceship) breaks
     *     the exact expected order.
     */
    public function testFindByParentsSortsMergedResultsBySortThenId(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // Queried first (Page class), but must sort last on Sort.
        $section = GridTreeFactory::section($page, sort: 2);
        // Queried second (Section class), but must sort first. Equal Sorts tie-break on ID.
        $rowA = GridTreeFactory::row($section, sort: 1);
        $rowB = GridTreeFactory::row($section, sort: 1);

        $results = $this->repository->findByParents([
            Page::class => [(int) $page->ID],
            Section::class => [(int) $section->ID],
        ]);

        $ids = array_map(static fn (GridElement $element): int => (int) $element->ID, $results);
        self::assertSame([(int) $rowA->ID, (int) $rowB->ID, (int) $section->ID], $ids);
    }

    public function testFindByParentsMultipleClasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Pass multiple parent classes — results combined
        $results = $this->repository->findByParents([
            Page::class => [(int) $page->ID],
            Section::class => [(int) $section->ID],
        ]);

        // Should return section (child of page) + row (child of section)
        self::assertCount(2, $results);

        $ids = array_map(static fn (GridElement $el): int => (int) $el->ID, $results);
        self::assertContains((int) $section->ID, $ids);
        self::assertContains((int) $row->ID, $ids);
    }

    public function testFindByParentsEmptyInput(): void
    {
        $results = $this->repository->findByParents([]);

        self::assertSame([], $results);
    }
}
