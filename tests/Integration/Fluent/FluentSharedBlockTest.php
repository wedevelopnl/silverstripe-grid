<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
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
use WeDevelop\Grid\Service\SharedBlockUsageResolver;
use WeDevelop\Grid\Service\LocalisedSubtreeCloner;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\SharedBlockDeleteMode;

/**
 * Shared blocks under Fluent: the block RECORD is one cross-locale row, its
 * SUBTREE is locale-isolated like every other grid element, and a placement is
 * an ordinary per-locale element pointing at the same block.
 */
#[CoversClass(FluentSharedBlockExtension::class)]
#[CoversClass(LocalisedSubtreeCloner::class)]
#[CoversClass(GridAwareDeleteLocalisationPolicy::class)]
#[CoversClass(SharedBlockService::class)]
#[CoversClass(SharedBlockUsageResolver::class)]
#[CoversClass(SharedBlock::class)]
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

    public function testRefusingAPageRootPlacementWithoutAZoneLocalisesNothing(): void
    {
        // The refusal must precede the localiser: a placement that is never
        // stored must not leave the block with content in a new locale.
        $block = $this->populatedBlock();

        FluentState::singleton()->setLocale('nl_NL');
        $page = $this->createPage('Dutch page');

        $result = Injector::inst()->get(SharedBlockService::class)
            ->place($block, $page, '', null);

        self::assertTrue($result->isErr());
        self::assertSame(0, $this->rootCount($block), 'the block gained no Dutch content');
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

    /**
     * "Opening hours" placed on "Contact" in English and Dutch, each locale
     * with its own wording of the block, everything published in both.
     */
    private function blockPublishedInBothLocales(): array
    {
        $page = $this->createPage('Contact');
        $block = $this->populatedBlock('Opening hours');
        GridTreeFactory::reference($page, $block, zone: 'main');

        // Copying the page carries the placement and localises the block.
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        $this->inLocale('nl_NL', static function () use ($block): void {
            $leaf = ContentElement::get()->filter(['Title' => 'Shared leaf'])->first();
            self::assertNotNull($leaf, 'precondition: Dutch holds its own block content');
            $leaf->Title = 'Gedeelde tekst';
            $leaf->write();
        });

        foreach (['en_US', 'nl_NL'] as $locale) {
            $this->inLocale($locale, static function () use ($block, $page): void {
                SharedBlock::get()->byID($block->ID)?->publishRecursive();
                Page::get()->byID($page->ID)?->publishRecursive();
            });
        }

        return ['block' => $block, 'page' => $page];
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function inLocale(string $locale, callable $callback): mixed
    {
        return FluentState::singleton()->withState(static function (FluentState $state) use ($locale, $callback): mixed {
            $state->setLocale($locale);

            return $callback();
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function onStage(string $stage, callable $callback): mixed
    {
        return Versioned::withVersionedMode(static function () use ($stage, $callback): mixed {
            Versioned::set_stage($stage);

            return $callback();
        });
    }

    /** Titles of the content elements on $page's grid in the active locale and stage. */
    private function pageLeafTitles(Page $page): array
    {
        $titles = [];

        foreach (Section::get()->filter(['ParentID' => $page->ID, 'ParentClass' => Page::class]) as $section) {
            foreach ($section->Rows() as $row) {
                foreach ($row->getChildren() as $column) {
                    foreach ($column->getChildren() as $leaf) {
                        $titles[] = (string) $leaf->Title;
                    }
                }
            }
        }

        return $titles;
    }

    /** @return int<0, max> */
    private function placementCount(SharedBlock $block): int
    {
        return SharedBlockReference::get()->filter(['BlockID' => $block->ID])->count();
    }

    public function testKeepingTheContentGivesEveryLocaleItsOwnCopy(): void
    {
        ['block' => $block, 'page' => $page] = $this->blockPublishedInBothLocales();

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Unshare);

        self::assertTrue($result->isOk(), 'the delete must succeed');
        self::assertNull(SharedBlock::get()->byID($block->ID), 'the block left the library');

        $expected = ['en_US' => 'Shared leaf', 'nl_NL' => 'Gedeelde tekst'];

        foreach ($expected as $locale => $title) {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                [$titles, $placements] = $this->inLocale($locale, fn (): array => $this->onStage(
                    $stage,
                    fn (): array => [$this->pageLeafTitles($page), $this->placementCount($block)],
                ));

                self::assertSame([$title], $titles, "{$locale} {$stage} keeps its own wording of the block");
                self::assertSame(0, $placements, "{$locale} {$stage} holds no placement any more");
            }
        }
    }

    public function testRemovingTheBlockLeavesNothingInAnyLocale(): void
    {
        ['block' => $block, 'page' => $page] = $this->blockPublishedInBothLocales();

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove);

        self::assertTrue($result->isOk());

        foreach (['en_US', 'nl_NL'] as $locale) {
            foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                [$placements, $roots, $titles] = $this->inLocale($locale, fn (): array => $this->onStage(
                    $stage,
                    fn (): array => [$this->placementCount($block), $this->rootCount($block), $this->pageLeafTitles($page)],
                ));

                self::assertSame(0, $placements, "{$locale} {$stage}: no stranded placement");
                self::assertSame(0, $roots, "{$locale} {$stage}: no orphaned block subtree");
                self::assertSame([], $titles, "{$locale} {$stage}: the page shows nothing of the block");
            }
        }
    }

    public function testKeepingTheContentRemovesPlacementsInALocaleWithoutContent(): void
    {
        // Those placements render nothing today, so removing them keeps the
        // page showing exactly what it showed.
        ['block' => $block, 'page' => $page] = $this->blockPublishedInBothLocales();

        $this->inLocale('nl_NL', function () use ($block): void {
            foreach (GridElement::get()->filter(['ParentID' => $block->ID, 'ParentClass' => SharedBlock::class]) as $root) {
                $root->doArchive();
            }
        });

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Unshare);

        self::assertTrue($result->isOk(), 'English still has content to keep');

        [$nlPlacements, $nlTitles] = $this->inLocale('nl_NL', fn (): array => [
            $this->placementCount($block),
            $this->pageLeafTitles($page),
        ]);
        self::assertSame(0, $nlPlacements, 'the Dutch placement is gone');
        self::assertSame([], $nlTitles, 'and Dutch gained no copy of English content');

        self::assertSame(['Shared leaf'], $this->pageLeafTitles($page), 'English kept its copy');
    }

    public function testDeletingRecordsANewDraftVersionOfThePageInEveryLocale(): void
    {
        ['block' => $block, 'page' => $page] = $this->blockPublishedInBothLocales();

        $version = fn (): int => (int) Page::get()->byID($page->ID)?->Version;
        $before = ['en_US' => $this->inLocale('en_US', $version), 'nl_NL' => $this->inLocale('nl_NL', $version)];

        Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove)->unwrap();

        foreach ($before as $locale => $previous) {
            self::assertGreaterThan($previous, $this->inLocale($locale, $version), "{$locale} records the change");
        }
    }

    public function testAMemberRestrictedToOneLocaleCannotDeleteABlockPlacedInAnother(): void
    {
        ['block' => $block] = $this->blockPublishedInBothLocales();

        $this->logInWithPermission(['CMS_ACCESS_CMSMain', 'Fluent_Locale_en_US']);

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove);

        self::assertTrue($result->isErr(), 'the delete would write into Dutch, which this member may not edit');
        self::assertSame(
            'You can\'t delete "Opening hours": it is also placed on pages in Dutch, which you may not edit.'
                . ' Ask someone who can edit every language this block is placed in.',
            $result->errors()[0]->translate(),
        );
        self::assertNotNull(SharedBlock::get()->byID($block->ID), 'nothing was deleted');
        self::assertSame(1, $this->inLocale('nl_NL', fn (): int => $this->placementCount($block)));
        self::assertSame(1, $this->placementCount($block));
    }

    public function testTheMultiLocaleActionPermissionAllowsTheDelete(): void
    {
        ['block' => $block] = $this->blockPublishedInBothLocales();

        $this->logInWithPermission(['CMS_ACCESS_CMSMain', 'Fluent_Locale_en_US', 'Fluent_Actions_MultiLocale']);

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove);

        self::assertTrue($result->isOk());
        self::assertSame(0, $this->inLocale('nl_NL', fn (): int => $this->placementCount($block)));
    }

    public function testASiteWithoutLocalesYetStillDeletesThePlacements(): void
    {
        // Isolated, but no Locale created: Fluent filters nothing, so the one
        // ambient pass sees every row, as it did before the delete went
        // locale by locale.
        foreach (Locale::get() as $locale) {
            $locale->delete();
        }
        Locale::clearCached();

        $page = $this->createPage('Contact');
        $block = $this->populatedBlock('Opening hours');
        GridTreeFactory::reference($page, $block, zone: 'main');

        $result = Injector::inst()->get(SharedBlockService::class)->delete($block, SharedBlockDeleteMode::Remove);

        self::assertSame(1, $result->unwrap(), 'the placement was counted and handled');
        self::assertSame(0, $this->placementCount($block), 'no placement of the deleted block is left');
        self::assertSame(0, $this->rootCount($block), 'nor its content');
    }

    public function testUsageCountsEveryLocaleAndAPageInSeveralLocalesOnce(): void
    {
        ['block' => $block] = $this->blockPublishedInBothLocales();

        $this->inLocale('nl_NL', function () use ($block): void {
            $dutchOnly = $this->createPage('Over ons');
            GridTreeFactory::reference($dutchOnly, $block, zone: 'main');
        });

        $resolver = SharedBlockUsageResolver::create();

        self::assertSame(2, $resolver->usageCount($block), 'Contact once, Over ons once');
        self::assertCount(2, $resolver->pagesUsing($block));
    }
}
