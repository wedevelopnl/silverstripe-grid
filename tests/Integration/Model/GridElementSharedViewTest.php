<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\PermissionDenyingPage;

/**
 * A block's content is page content wherever it is placed. The usage resolver
 * memoises per block for the ambient stage, so every test switches to LIVE
 * BEFORE the first canView() call.
 */
#[CoversClass(GridElement::class)]
final class GridElementSharedViewTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string> */
    protected static $extra_dataobjects = [
        PermissionDenyingPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testAnonymousVisitorMayViewBlockContentPlacedOnAPublishedPage(): void
    {
        [$block, $leaf] = $this->publishedBlockWithLeaf();
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::reference($page, $block, sort: 1);
        $page->publishRecursive();

        self::assertTrue($this->liveAnonymous($leaf)->canView());
    }

    public function testAnonymousVisitorMayNotViewContentOfAnUnplacedBlock(): void
    {
        [, $leaf] = $this->publishedBlockWithLeaf();

        self::assertFalse($this->liveAnonymous($leaf)->canView());
    }

    public function testPlacementOnAPageTheVisitorCannotViewGrantsNothing(): void
    {
        [$block, $leaf] = $this->publishedBlockWithLeaf();
        $page = PermissionDenyingPage::create();
        $page->Title = 'Denied';
        $page->URLSegment = 'denied';
        $page->write();
        GridTreeFactory::reference($page, $block, sort: 1);
        $page->publishRecursive();

        self::assertFalse($this->liveAnonymous($leaf)->canView());
    }

    public function testDraftOnlyPlacementGrantsNothingOnLive(): void
    {
        [$block, $leaf] = $this->publishedBlockWithLeaf();
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishRecursive();
        // Placed after the publish: the reference exists on DRAFT only.
        GridTreeFactory::reference($page, $block, sort: 1);

        self::assertFalse($this->liveAnonymous($leaf)->canView());
    }

    public function testCmsMemberStillViewsContentOfAnUnplacedBlock(): void
    {
        [, $leaf] = $this->publishedBlockWithLeaf();
        $this->logInWithPermission('CMS_ACCESS_LeftAndMain');

        self::assertTrue($leaf->canView());
    }

    public function testTheBlockRecordItselfStaysOnTheLibraryGate(): void
    {
        [$block] = $this->publishedBlockWithLeaf();
        $page = $this->objFromFixture(Page::class, 'test_page');
        GridTreeFactory::reference($page, $block, sort: 1);
        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);
        $this->logOut();

        self::assertFalse(SharedBlock::get()->byID($block->ID)?->canView());
    }

    /**
     * Section-rooted block (Section → Row → Column → leaf), published. The
     * leaf sits three levels deep, so the getPage() walk is exercised.
     *
     * @return array{SharedBlock, ContentElement}
     */
    private function publishedBlockWithLeaf(): array
    {
        $block = GridTreeFactory::sharedBlock('Banner');
        $column = GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($block)));
        $leaf = GridTreeFactory::contentElement($column, title: 'Leaf');
        $block->publishRecursive();

        return [$block, $leaf];
    }

    private function liveAnonymous(ContentElement $leaf): ContentElement
    {
        Versioned::set_stage(Versioned::LIVE);
        $this->logOut();

        $live = ContentElement::get()->byID($leaf->ID);
        self::assertInstanceOf(ContentElement::class, $live);

        return $live;
    }
}
