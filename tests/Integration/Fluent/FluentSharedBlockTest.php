<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use TractorCow\Fluent\Service\CopyToLocaleService;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Extensions\FluentSharedBlockExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Service\GridAwareDeleteLocalisationPolicy;
use WeDevelop\Grid\Service\GridTreeService;
use WeDevelop\Grid\Service\SharedBlockService;
use WeDevelop\Grid\Service\LocalisedSubtreeCloner;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Shared blocks under Fluent: the block RECORD is one cross-locale row, its
 * SUBTREE is locale-isolated like every other grid element, and a placement is
 * an ordinary per-locale element pointing at the same block.
 */
#[CoversClass(FluentSharedBlockExtension::class)]
#[CoversClass(LocalisedSubtreeCloner::class)]
#[CoversClass(GridAwareDeleteLocalisationPolicy::class)]
final class FluentSharedBlockTest extends FluentGridTestCase
{
    /** A block with a Section > Row > Column > leaf subtree in the active locale. */
    private function populatedBlock(string $title = 'Shared banner'): SharedBlock
    {
        $block = GridTreeFactory::sharedBlock($title);
        $section = GridTreeFactory::section($block, zone: '', title: 'Shared section');
        GridTreeFactory::contentElement(
            GridTreeFactory::column(GridTreeFactory::row($section)),
            title: 'Shared leaf',
        );

        return $block;
    }

    /** @return int<0, max> */
    private function rootCount(SharedBlock $block): int
    {
        return GridElement::get()->filter([
            'ParentID' => $block->ID,
            'ParentClass' => SharedBlock::class,
        ])->count();
    }

    public function testBlockSubtreeIsLocaleIsolated(): void
    {
        $block = $this->populatedBlock();

        self::assertSame(1, $this->rootCount($block), 'the English subtree exists');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(0, $this->rootCount($block), 'Dutch has no subtree of its own yet');

        FluentState::singleton()->setLocale('en_US');
        self::assertSame(1, $this->rootCount($block));
    }

    public function testCopyBlockToLocaleClonesSubtree(): void
    {
        $block = $this->populatedBlock();
        $enSectionId = (int) $block->getRootElement()?->ID;

        CopyToLocaleService::singleton()->copyToLocale(
            SharedBlock::class,
            (int) $block->ID,
            'en_US',
            'nl_NL',
        );

        FluentState::singleton()->setLocale('nl_NL');

        $nlRoots = GridElement::get()->filter([
            'ParentID' => $block->ID,
            'ParentClass' => SharedBlock::class,
        ]);
        self::assertCount(1, $nlRoots, 'Dutch gets its own root');

        $nlSection = $nlRoots->first();
        self::assertInstanceOf(Section::class, $nlSection);
        self::assertNotSame($enSectionId, (int) $nlSection->ID, 'a new record, not the English one');
        self::assertSame('Shared section', $nlSection->Title);

        // ... and the whole subtree came with it.
        $nlRow = $nlSection->Rows()->first();
        self::assertInstanceOf(Row::class, $nlRow);
        $nlColumn = $nlRow->Columns()->first();
        self::assertNotNull($nlColumn);
        $nlLeaf = $nlColumn->getChildren()->first();
        self::assertInstanceOf(ContentElement::class, $nlLeaf);
        self::assertSame('Shared leaf', $nlLeaf->Title);
    }

    public function testCopyBlockToLocaleIsIdempotent(): void
    {
        $block = $this->populatedBlock();

        CopyToLocaleService::singleton()->copyToLocale(SharedBlock::class, (int) $block->ID, 'en_US', 'nl_NL');
        CopyToLocaleService::singleton()->copyToLocale(SharedBlock::class, (int) $block->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(1, $this->rootCount($block), 'a second copy adds nothing');
    }

    public function testPageCopyLocalisesTheBlocksItPlaces(): void
    {
        // Localising a page localises the blocks on it. The block RECORD stays
        // one cross-locale row — the placement still points at the same ID — but
        // the target locale gains its own subtree, so the copied page renders
        // rather than showing an empty frame the author has no signal about.
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');
        $enRootId = (int) $block->getRootElement()?->ID;

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        $nlReferences = SharedBlockReference::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => Page::class,
        ]);
        self::assertCount(1, $nlReferences, 'the placement is per-locale page content');
        self::assertSame(
            (int) $block->ID,
            (int) $nlReferences->first()?->BlockID,
            'every locale points at the same block — the record is not forked',
        );

        self::assertSame(1, $this->rootCount($block), 'Dutch gained its own subtree');

        $nlRoot = GridElement::get()
            ->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])
            ->first();
        self::assertNotNull($nlRoot);
        self::assertNotSame($enRootId, (int) $nlRoot->ID, 'a new record, not the English one');

        FluentState::singleton()->setLocale('en_US');
        self::assertSame(1, $this->rootCount($block), 'the English subtree is untouched');
        self::assertSame($enRootId, (int) $block->getRootElement()?->ID);
    }

    public function testPageCopyLocalisesAMidTreePlacement(): void
    {
        // Placements are not only page roots: a row-rooted block sits inside a
        // Section. A pass that walked only the cloned ROOTS would miss it and
        // leave the block unlocalised, so this is the case that separates a
        // whole-subtree walk from a root-only one.
        $page = $this->createPage();

        $section = GridTreeFactory::section($page, zone: 'main');

        $block = GridTreeFactory::sharedBlock('Row block');
        $blockRoot = Row::create();
        $blockRoot->ParentID = (int) $block->ID;
        $blockRoot->ParentClass = SharedBlock::class;
        $blockRoot->write();
        GridTreeFactory::column($blockRoot);

        GridTreeFactory::reference($section, $block, zone: '');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(1, $this->rootCount($block), 'the mid-tree placement localised its block');
        self::assertInstanceOf(
            Row::class,
            GridElement::get()->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])->first(),
            'the Dutch copy keeps the row-rooted shape',
        );
    }

    public function testPageCopyLocalisesEveryDistinctBlockOnce(): void
    {
        $page = $this->createPage();
        $first = $this->populatedBlock('First');
        $second = $this->populatedBlock('Second');

        GridTreeFactory::reference($page, $first, zone: 'main', sort: 1);
        GridTreeFactory::reference($page, $second, zone: 'main', sort: 2);
        // The same block placed twice must still yield one Dutch subtree.
        GridTreeFactory::reference($page, $first, zone: 'main', sort: 3);

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(1, $this->rootCount($first));
        self::assertSame(1, $this->rootCount($second));
    }

    public function testPageCopyDoesNotOverwriteAnAlreadyTranslatedBlock(): void
    {
        // The guard that makes this safe to run on every page copy: the clone
        // only ever runs into a locale that holds nothing, so a translation
        // already made in the target locale survives untouched.
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(SharedBlock::class, (int) $block->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        $nlRoot = GridElement::get()
            ->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])
            ->first();
        self::assertNotNull($nlRoot);
        $nlRoot->Title = 'Vertaalde sectie';
        $nlRoot->write();

        FluentState::singleton()->setLocale('en_US');
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(1, $this->rootCount($block), 'no second subtree');
        self::assertSame(
            'Vertaalde sectie',
            (string) GridElement::get()
                ->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])
                ->first()?->Title,
            'the translation must survive the page copy',
        );
    }

    public function testTwoPagesPlacingOneBlockProduceOneLocaleSubtree(): void
    {
        $first = $this->createPage('First page');
        $second = $this->createPage('Second page');
        $block = $this->populatedBlock();

        GridTreeFactory::reference($first, $block, zone: 'main');
        GridTreeFactory::reference($second, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $first->ID, 'en_US', 'nl_NL');
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $second->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(1, $this->rootCount($block), 'the second page copy must not fork the block');
    }

    public function testRepeatedPageCopyAddsNothing(): void
    {
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(1, $this->rootCount($block));
    }

    public function testPageCopySurvivesABlockThatIsEmptyInEveryLocale(): void
    {
        // An author may place a block before filling it. There is nothing to
        // copy, which is not an error — the placement still comes across.
        $page = $this->createPage();
        $block = GridTreeFactory::sharedBlock('Empty block');
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(0, $this->rootCount($block));
        self::assertCount(
            1,
            SharedBlockReference::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class]),
            'the placement is still carried across',
        );
    }

    public function testPageCopyLocalisesBlocksForAPageEditorWithoutLibraryGrant(): void
    {
        // Localising a block writes library records. Managing a block requires
        // PAGE access, so the author copying the page already holds the
        // authority — this pins that the copy does not silently skip blocks for
        // the very authors most likely to be doing translation work.
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        $this->logInWithPermission(['CMS_ACCESS_CMSMain', 'SITETREE_EDIT_ALL']);

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        self::assertSame(1, $this->rootCount($block));
    }

    public function testPlacingABlockInALocaleThatLacksItLocalisesIt(): void
    {
        // Reached without any page copy: an author working directly in Dutch
        // picks a block that so far exists only in English. Before this, the
        // block resolved no placement class in Dutch and the placement was
        // refused outright with BLOCK_EMPTY.
        $block = $this->populatedBlock();

        FluentState::singleton()->setLocale('nl_NL');
        $page = $this->createPage('Dutch page');

        $result = Injector::inst()->get(SharedBlockService::class)
            ->place($block, $page, 'main', null);

        self::assertTrue($result->isOk(), 'the placement must be accepted');
        self::assertSame(1, $this->rootCount($block), 'the block gained Dutch content');
    }

    public function testPlacingTheSameBlockTwiceInALocaleAddsOneSubtree(): void
    {
        $block = $this->populatedBlock();

        FluentState::singleton()->setLocale('nl_NL');
        $page = $this->createPage('Dutch page');

        $service = Injector::inst()->get(SharedBlockService::class);
        $service->place($block, $page, 'main', null)->unwrap();
        $service->place($block, $page, 'main', null)->unwrap();

        self::assertSame(1, $this->rootCount($block));
    }

    public function testClearingALocaleFromAPageLeavesTheBlockSubtree(): void
    {
        // Copy creates, clear does not destroy: the block is shared, so a single
        // page cannot unilaterally take its content away from other pages in
        // that locale.
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(1, $this->rootCount($block), 'precondition: Dutch has block content');

        Injector::inst()->get(GridAwareDeleteLocalisationPolicy::class)->delete($page);

        self::assertSame(1, $this->rootCount($block), 'the block keeps its Dutch subtree');
    }

    public function testTreeExpandsTheSubtreeOfTheActiveLocale(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(SharedBlock::class, (int) $block->ID, 'en_US', 'nl_NL');
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        // Rename the Dutch copy so the two locales are distinguishable.
        FluentState::singleton()->setLocale('nl_NL');
        $nlRoot = GridElement::get()
            ->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])
            ->first();
        self::assertNotNull($nlRoot);
        $nlRoot->Title = 'Gedeelde sectie';
        $nlRoot->write();

        $treeService = Injector::inst()->get(GridTreeService::class);

        $nlTree = $treeService->buildViewableTree($page, 'main');
        self::assertSame('Gedeelde sectie', $nlTree->nodes[0]->children[0]->title ?? null);

        FluentState::singleton()->setLocale('en_US');
        $enTree = $treeService->buildViewableTree($page, 'main');
        self::assertSame('Shared section', $enTree->nodes[0]->children[0]->title ?? null);
    }

    public function testReferenceWithoutLocaleContentYieldsEmptyChildren(): void
    {
        // A page copy now localises the block too, so the gap state is reached
        // the way it still occurs in practice: the block is EMPTIED in this
        // locale after it was placed. The invariant is unchanged — a placement
        // that resolves nothing must render empty rather than throw.
        $this->logInWithPermission('ADMIN');

        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        $nlRoot = GridElement::get()
            ->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class])
            ->first();
        self::assertNotNull($nlRoot, 'precondition: the copy localised the block');
        $nlRoot->delete();

        $tree = Injector::inst()->get(GridTreeService::class)->buildViewableTree($page, 'main');

        self::assertCount(1, $tree->nodes);
        self::assertNotNull($tree->nodes[0]->sharedBlock);
        self::assertSame([], $tree->nodes[0]->children, 'no content for this locale renders empty');
    }

    public function testDeleteLocalisationRemovesOnlyThatLocaleSubtree(): void
    {
        $block = $this->populatedBlock();
        CopyToLocaleService::singleton()->copyToLocale(SharedBlock::class, (int) $block->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(1, $this->rootCount($block), 'precondition: Dutch has content');

        Injector::inst()->get(GridAwareDeleteLocalisationPolicy::class)->delete($block);

        self::assertSame(0, $this->rootCount($block), 'the Dutch subtree is gone');

        FluentState::singleton()->setLocale('en_US');
        self::assertSame(1, $this->rootCount($block), 'English is intact');
    }

    public function testDeleteLocalisationRemovesThatLocalePlacements(): void
    {
        // A placement is a root element but not a Section, so the old
        // Sections()-only cleanup stranded it in a deleted locale.
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');
        self::assertSame(1, SharedBlockReference::get()->filter('ParentID', $page->ID)->count());

        Injector::inst()->get(GridAwareDeleteLocalisationPolicy::class)->delete($page);

        self::assertSame(
            0,
            SharedBlockReference::get()->filter('ParentID', $page->ID)->count(),
            'the Dutch placement is gone',
        );

        FluentState::singleton()->setLocale('en_US');
        self::assertSame(
            1,
            SharedBlockReference::get()->filter('ParentID', $page->ID)->count(),
            'the English placement is intact',
        );
        self::assertSame(1, $this->rootCount($block), 'and the block itself is untouched');
    }

    public function testPageCopyStillClonesAPlainGridTree(): void
    {
        // Regression for the LocalisedSubtreeCloner extraction: the page path
        // must keep working exactly as before.
        $page = $this->createPage();
        $section = GridTreeFactory::section($page, title: 'Hero Section');
        GridTreeFactory::contentElement(
            GridTreeFactory::column(GridTreeFactory::row($section, title: 'Hero Row')),
            title: 'Hero Content',
        );

        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

        $nlSection = Section::get()
            ->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class])
            ->first();
        self::assertInstanceOf(Section::class, $nlSection);
        self::assertSame('Hero Section', $nlSection->Title);
        self::assertNotSame((int) $section->ID, (int) $nlSection->ID);

        $nlRow = $nlSection->Rows()->first();
        self::assertInstanceOf(Row::class, $nlRow);
        $nlLeaf = $nlRow->Columns()->first()?->getChildren()->first();
        self::assertInstanceOf(ContentElement::class, $nlLeaf);
        self::assertSame('Hero Content', $nlLeaf->Title);
    }
}
