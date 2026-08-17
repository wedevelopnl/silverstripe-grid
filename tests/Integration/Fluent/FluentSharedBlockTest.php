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

    public function testPageCopyClonesTheReferenceNotTheBlockSubtree(): void
    {
        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

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
            'every locale points at the same block',
        );

        self::assertSame(
            0,
            $this->rootCount($block),
            'copying a page must not duplicate the block subtree across the boundary',
        );

        FluentState::singleton()->setLocale('en_US');
        self::assertSame(1, $this->rootCount($block), 'the English subtree is untouched');
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
        $this->logInWithPermission('ADMIN');

        $page = $this->createPage();
        $block = $this->populatedBlock();
        GridTreeFactory::reference($page, $block, zone: 'main');

        // Copy only the PAGE, so Dutch has a placement but no block content.
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        FluentState::singleton()->setLocale('nl_NL');

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
