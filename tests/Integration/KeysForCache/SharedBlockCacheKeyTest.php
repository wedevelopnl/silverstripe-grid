<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\KeysForCache;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use Terraformers\KeysForCache\Services\ProcessedUpdatesService;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

/**
 * Guards that a placement's cache key follows its block's content, which it
 * does not hold itself: the edge runs back from the block to its placements.
 */
#[CoversNothing]
final class SharedBlockCacheKeyTest extends SapphireTest
{
    use DisablesAutoScaffolding;
    use ReadsCacheKeys;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testPublishedBlockChangeInvalidatesEveryPlacement(): void
    {
        $block = GridTreeFactory::sharedBlock();
        $content = GridTreeFactory::contentElement(
            GridTreeFactory::column(GridTreeFactory::row(GridTreeFactory::section($block))),
        );
        $block->publishRecursive();

        $placements = [];
        foreach (['test_page', 'test_page_2'] as $fixture) {
            $page = $this->objFromFixture(Page::class, $fixture);
            $placements[$fixture] = GridTreeFactory::reference($page, $block);
            $page->publishRecursive();
        }
        $before = self::liveKeysOf($placements);

        ProcessedUpdatesService::singleton()->flush();
        $content->Title = 'Changed';
        $content->write();
        $content->publishRecursive();

        $after = self::liveKeysOf($placements);
        foreach ($before as $page => $key) {
            self::assertNotSame('', $key, 'the placement on ' . $page . ' must have a live key');
            self::assertNotSame($key, $after[$page], 'the placement on ' . $page . ' must get a new live key');
        }
    }

    public function testBlockFormDoesNotScaffoldTheReverseRelation(): void
    {
        $block = GridTreeFactory::sharedBlock();

        self::assertNull($block->getCMSFields()->dataFieldByName('References'));
    }
}
