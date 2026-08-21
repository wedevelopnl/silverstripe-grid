<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GrantDeleteExtension;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\VetoBlockDeleteExtension;

#[CoversClass(SharedBlock::class)]
#[CoversClass(SharedBlockReference::class)]
#[CoversClass(GridElement::class)]
final class SharedBlockTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testSubtreeParentsToBlockViaPolymorphicRelation(): void
    {
        $block = GridTreeFactory::sharedBlock();
        $section = GridTreeFactory::section($block, zone: '');

        self::assertCount(1, $block->RootElements());
        self::assertSame((int) $section->ID, (int) $block->getRootElement()?->ID);
    }

    public function testGetRootElementReturnsNullWithoutChildren(): void
    {
        $block = GridTreeFactory::sharedBlock();

        self::assertNull($block->getRootElement());
    }

    public function testGetRootElementReturnsNullForUnsavedBlock(): void
    {
        // An unsaved block has ID 0; RootElements() would otherwise query
        // ParentID = 0 and could match orphaned rows.
        self::assertNull(SharedBlock::create()->getRootElement());
    }

    public function testPublishRecursiveCascadesThroughSubtree(): void
    {
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();
        $section = GridTreeFactory::section($block, zone: '');
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $leaf = GridTreeFactory::contentElement($column);

        $block->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);

        self::assertInstanceOf(Section::class, Section::get()->byID($section->ID), 'Section should exist on LIVE');
        self::assertInstanceOf(Row::class, Row::get()->byID($row->ID), 'Row should exist on LIVE');
        self::assertInstanceOf(Column::class, Column::get()->byID($column->ID), 'Column should exist on LIVE');
        self::assertInstanceOf(
            ContentElement::class,
            ContentElement::get()->byID($leaf->ID),
            'Content element should exist on LIVE',
        );
    }

    public function testCanDeleteWhileReferenced(): void
    {
        // Being placed is not a veto: the library resolves what happens to the
        // consuming pages instead of refusing the delete.
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        GridTreeFactory::reference($page, $block);

        self::assertTrue($block->canDelete());
    }

    public function testCanDeleteTrueWhenUnreferenced(): void
    {
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');

        self::assertTrue($block->canDelete());
    }

    public function testDeletingBlockArchivesItsPlacements(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        $block->doArchive();

        self::assertNull(
            SharedBlockReference::get()->byID($reference->ID),
            'a placement left behind resolves no root class and breaks later writes on that page',
        );
    }

    public function testDeletingBlockRemovesPlacementsFromLive(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);
        $reference->publishSingle();

        $block->doArchive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertNull(
            SharedBlockReference::get()->byID($reference->ID),
            'the delete must reach the public site, not wait for each page to be republished',
        );
    }

    public function testDeletingBlockRemovesPlacementsThatSurviveOnLiveAlone(): void
    {
        // A placement whose draft row was dropped without unpublishing is
        // invisible to a draft-stage sweep, so it needs its own cleanup pass.
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        $reference->publishSingle();
        $reference->deleteFromStage(Versioned::DRAFT);

        self::assertSame(
            0,
            SharedBlockReference::get()->filter('BlockID', $block->ID)->count(),
            'precondition: the reference is gone from DRAFT',
        );

        $block->doArchive();

        Versioned::set_stage(Versioned::LIVE);
        self::assertNull(SharedBlockReference::get()->byID($reference->ID));
    }

    public function testSubtreeElementRemainsDeletableWhileBlockIsReferenced(): void
    {
        // GridElement::canDelete() delegates to its owning record, and for a
        // block that means canEdit(), not canDelete(). The veto extension is
        // what makes the two answers differ: without it both resolve to the same
        // permission code for every member, so swapping canEdit() for
        // canDelete() in GridElement would leave this test green.
        SharedBlock::add_extension(VetoBlockDeleteExtension::class);
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        $section = GridTreeFactory::section($block, zone: '');
        $row = GridTreeFactory::row($section);
        GridTreeFactory::reference($page, $block);

        self::assertFalse($block->canDelete(), 'precondition: the block itself refuses deletion');
        self::assertTrue($row->canDelete(), 'elements inside the block must stay deletable');
    }

    /**
     * The root is the block's whole subtree: deleting it would leave a block
     * that resolves no effective root class, so every consuming page renders
     * nothing and later grid writes there fail with BLOCK_EMPTY. The block is
     * deleted as a whole instead.
     */
    public function testTheBlockRootIsNeverDeletable(): void
    {
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();
        $root = GridTreeFactory::section($block, zone: '');
        $row = GridTreeFactory::row($root);

        self::assertTrue($block->canEdit(), 'precondition: an admin may edit the block');
        self::assertFalse($root->canDelete(), 'the block root carries no delete');
        self::assertTrue($row->canDelete(), 'only the root is protected, not its subtree');
    }

    /**
     * A leaf-rooted block has no container to hide behind — its single element
     * IS the root, and the same protection applies.
     */
    public function testTheRootOfALeafRootedBlockIsNeverDeletable(): void
    {
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();

        $leaf = ContentElement::create();
        $leaf->ParentID = $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        self::assertFalse($leaf->canDelete());
    }

    /**
     * The veto is a structural invariant, not a permission, so it sits above
     * the extension hook: an updateCanDelete that grants deletion must not be
     * able to strand a block.
     */
    public function testAnExtensionCannotGrantDeletionOfTheBlockRoot(): void
    {
        GridElement::add_extension(GrantDeleteExtension::class);
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();
        $root = GridTreeFactory::section($block, zone: '');
        $pageElement = GridTreeFactory::section($this->objFromFixture(Page::class, 'test_page'));

        self::assertTrue($pageElement->canDelete(), 'precondition: the extension grants deletion');
        self::assertFalse($root->canDelete());
    }

    /**
     * The framework's own removal paths do not consult canDelete(), so deleting
     * the block still takes its subtree with it through $cascade_deletes.
     */
    public function testDeletingTheBlockStillCascadesToItsProtectedRoot(): void
    {
        $this->logInWithPermission('ADMIN');

        $block = GridTreeFactory::sharedBlock();
        $root = GridTreeFactory::section($block, zone: '');
        $rootId = (int) $root->ID;

        $block->delete();

        self::assertNull(Section::get()->byID($rootId));
    }

    public function testReferenceIsNotScaffolded(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        GridTreeFactory::reference($page, $block);

        self::assertSame(
            0,
            GridElement::get()->filter('ParentClass', SharedBlockReference::class)->count(),
        );
    }

    public function testEffectiveRootClassMatchesBlockRoot(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        self::assertSame(Section::class, $reference->getEffectiveRootClass());
    }

    public function testEffectiveRootClassNullOnceBlockIsEmptied(): void
    {
        // An author can empty a block that is already placed; the reference then
        // survives with nothing to stand in for.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        $section = GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        $section->delete();

        self::assertNull($reference->getEffectiveRootClass());
    }

    public function testEffectiveRootClassNullForDanglingReference(): void
    {
        // Placement validation refuses to write a reference with no block, so
        // this state only arises from raw DB damage. It must still resolve to
        // null rather than querying ParentID = 0 and matching orphaned rows.
        $reference = SharedBlockReference::create();
        $reference->BlockID = 0;

        self::assertNull($reference->getEffectiveRootClass());
    }

    public function testSortIsZoneScopedAtPageRoot(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');

        GridTreeFactory::section($page, zone: 'main', sort: 3);
        GridTreeFactory::section($page, zone: 'sidebar', sort: 1);

        $reference = GridTreeFactory::reference($page, $block, zone: 'sidebar');

        self::assertSame(2, (int) $reference->Sort, 'Sort continues the sidebar sequence, not main');
    }

    public function testForTemplateRendersTheBlockRootMarkup(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        $section = GridTreeFactory::section($block, zone: '', title: 'Shared section');
        GridTreeFactory::column(GridTreeFactory::row($section));
        $reference = GridTreeFactory::reference($page, $block);

        $block->publishRecursive();
        $reference->publishSingle();

        Versioned::set_stage(Versioned::LIVE);

        $liveReference = SharedBlockReference::get()->byID($reference->ID);
        $liveSection = Section::get()->byID($section->ID);
        self::assertInstanceOf(SharedBlockReference::class, $liveReference);
        self::assertInstanceOf(Section::class, $liveSection);

        // Byte-identical: the reference contributes no wrapper of its own.
        self::assertSame($liveSection->forTemplate(), $liveReference->forTemplate());
        self::assertStringContainsString('data-element', $liveReference->forTemplate());
    }

    public function testForTemplateIsEmptyWhenTheBlockIsNotPublished(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block);

        $reference->publishSingle();

        Versioned::set_stage(Versioned::LIVE);
        $liveReference = SharedBlockReference::get()->byID($reference->ID);
        self::assertInstanceOf(SharedBlockReference::class, $liveReference);

        self::assertSame('', $liveReference->forTemplate());
    }

    public function testForTemplateIsEmptyWhenTheBlockIsMissing(): void
    {
        // Defensive: raw DB damage must render empty, never throw.
        $reference = SharedBlockReference::create();
        $reference->BlockID = 0;

        self::assertSame('', $reference->forTemplate());
    }

    public function testSortFollowsExistingReferencesInTheSameZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');

        $first = GridTreeFactory::reference($page, $block, zone: 'main');
        $second = GridTreeFactory::reference($page, $block, zone: 'main');

        self::assertSame(1, (int) $first->Sort);
        self::assertSame(2, (int) $second->Sort);
    }

    public function testRootTypeLabelNamesTheRootElementType(): void
    {
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');

        self::assertSame('Section', $block->getRootTypeLabel());
    }

    public function testRootTypeLabelNamesALeafRootedBlockAfterItsElementType(): void
    {
        $block = GridTreeFactory::sharedBlock();
        $leaf = ContentElement::create();
        $leaf->ParentID = (int) $block->ID;
        $leaf->ParentClass = SharedBlock::class;
        $leaf->write();

        self::assertSame('Content element', $block->getRootTypeLabel());
    }

    public function testRootTypeLabelFallsBackToEmptyForABlockWithNoRoot(): void
    {
        self::assertSame('Empty', GridTreeFactory::sharedBlock()->getRootTypeLabel());
    }
}
