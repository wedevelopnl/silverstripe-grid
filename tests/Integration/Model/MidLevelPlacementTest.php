<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * The two MID-LEVEL placement shapes: a row-rooted block inside a Section and a
 * column-rooted block inside a Row.
 *
 * Both are first-class, UI-reachable flows, but every consumer used to reach
 * them through a class-narrowed has_many (`Rows` typed to Row, `Columns` to
 * Column) — so the placement was shown by the editor and then silently skipped
 * by ownership, publish, cascade delete, cascade duplicate and the `$Rows` /
 * `$Columns` template loops. Page-root and in-Column placements were unaffected
 * and are covered elsewhere, which is exactly why this hole survived.
 */
#[CoversClass(Section::class)]
#[CoversClass(Row::class)]
#[CoversClass(GridElement::class)]
final class MidLevelPlacementTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    /**
     * A block whose ROOT is of $rootClass — the class the placement rules judge
     * a reference to it by. GridTreeFactory's row()/column() are typed to their
     * page-side parents, and a block root is parented by the SharedBlock.
     *
     * @param class-string<GridElement> $rootClass
     */
    private function blockRootedBy(string $rootClass, string $title): SharedBlockReference
    {
        $block = GridTreeFactory::sharedBlock($title);

        $root = $rootClass::create();
        $root->ParentID = $block->ID;
        $root->ParentClass = SharedBlock::class;
        $root->write();

        $reference = SharedBlockReference::create();
        $reference->BlockID = $block->ID;

        return $reference;
    }

    /** A block rooted by a Row, placed inside $section. */
    private function placeRowRootedBlock(Section $section): SharedBlockReference
    {
        $reference = $this->blockRootedBy(Row::class, 'Row block');
        $reference->ParentID = $section->ID;
        $reference->ParentClass = Section::class;
        $reference->write();

        return $reference;
    }

    /** A block rooted by a Column, placed inside $row. */
    private function placeColumnRootedBlock(Row $row): SharedBlockReference
    {
        $reference = $this->blockRootedBy(Column::class, 'Column block');
        $reference->ParentID = $row->ID;
        $reference->ParentClass = Row::class;
        $reference->write();

        return $reference;
    }

    private function existsOnLive(SharedBlockReference $reference): bool
    {
        return Versioned::withVersionedMode(static function () use ($reference): bool {
            Versioned::set_stage(Versioned::LIVE);

            return SharedBlockReference::get()->byID($reference->ID) !== null;
        });
    }

    public function testSectionChildrenIncludeARowRootedPlacement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        GridTreeFactory::row($section);
        $reference = $this->placeRowRootedBlock($section);

        // Rows() is what Section.ss loops as $Rows; a placement missing from it
        // renders nothing on the front end while the editor claims it is there.
        $childIds = $section->Rows()->column('ID');

        self::assertContains((int) $reference->ID, array_map(intval(...), $childIds));
        self::assertCount(2, $section->getChildren());
    }

    public function testRowChildrenIncludeAColumnRootedPlacement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        GridTreeFactory::column($row);
        $reference = $this->placeColumnRootedBlock($row);

        $childIds = $row->Columns()->column('ID');

        self::assertContains((int) $reference->ID, array_map(intval(...), $childIds));
        self::assertCount(2, $row->getChildren());
    }

    public function testPublishingThePagePublishesARowRootedPlacement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $reference = $this->placeRowRootedBlock($section);

        $page->publishRecursive();

        self::assertTrue(
            $this->existsOnLive($reference),
            'the placement must reach LIVE with the page that carries it',
        );
    }

    public function testPublishingThePagePublishesAColumnRootedPlacement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $row = GridTreeFactory::row(GridTreeFactory::section($page));
        $reference = $this->placeColumnRootedBlock($row);

        $page->publishRecursive();

        self::assertTrue($this->existsOnLive($reference));
    }

    public function testArchivingTheSectionTakesItsPlacementWithIt(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $reference = $this->placeRowRootedBlock($section);

        $section->doArchive();

        self::assertNull(
            SharedBlockReference::get()->byID($reference->ID),
            'cascade_deletes must reach the placement, or it is left orphaned',
        );
    }

    public function testDuplicatingTheSectionCopiesItsPlacement(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $reference = $this->placeRowRootedBlock($section);

        $copy = $section->duplicate(true);

        $copiedReferences = SharedBlockReference::get()->filter([
            'ParentID' => $copy->ID,
            'ParentClass' => Section::class,
        ]);

        self::assertCount(1, $copiedReferences);
        self::assertSame(
            (int) $reference->BlockID,
            (int) $copiedReferences->first()?->BlockID,
            'the copy places the same block; only the placement is new',
        );
    }

    public function testASectionHoldingOnlyAPlacementDoesNotScaffoldAPhantomRow(): void
    {
        $this->enableAutoScaffolding();

        $page = $this->objFromFixture(Page::class, 'test_page');
        // Written before the placement exists, so it scaffolds its own Row.
        $section = GridTreeFactory::section($page);
        Row::get()->filter(['ParentID' => $section->ID, 'ParentClass' => Section::class])
            ->first()
            ?->delete();

        $this->placeRowRootedBlock($section);

        $section->Title = 'Renamed';
        $section->write();

        self::assertCount(
            0,
            Row::get()->filter(['ParentID' => $section->ID, 'ParentClass' => Section::class]),
            'a placement standing in for the Row must suppress the scaffold',
        );
        self::assertCount(1, $section->getChildren());
    }

    public function testARowHoldingOnlyAPlacementDoesNotScaffoldAPhantomColumn(): void
    {
        $this->enableAutoScaffolding();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $row = GridTreeFactory::row($section);
        Column::get()->filter(['ParentID' => $row->ID, 'ParentClass' => Row::class])
            ->first()
            ?->delete();

        $this->placeColumnRootedBlock($row);

        $row->Title = 'Renamed';
        $row->write();

        self::assertCount(
            0,
            Column::get()->filter(['ParentID' => $row->ID, 'ParentClass' => Row::class]),
        );
        self::assertCount(1, $row->getChildren());
    }
}
