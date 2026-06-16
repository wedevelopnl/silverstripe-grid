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
 * Guards the keys-for-cache integration: cache-key presence on grid elements
 * and transitive upward invalidation through the downward `cares` graph.
 */
#[CoversNothing]
final class GridCacheKeyTest extends SapphireTest
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

    public function testGridElementsExposeCacheKey(): void
    {
        $tree = GridTreeFactory::containerTree($this->objFromFixture(Page::class, 'test_page'));

        foreach (self::freshKeysOf($tree) as $level => $key) {
            self::assertNotSame('', $key, $level . ' must have a cache key');
        }
    }

    public function testDescendantChangeInvalidatesAncestorKeys(): void
    {
        $tree = GridTreeFactory::containerTree($this->objFromFixture(Page::class, 'test_page'));
        $before = self::freshKeysOf($tree);

        // KFC updates a record's key once per request, and a test is one
        // request: building the tree already used that up. KFC's own testing
        // docs prescribe this flush.
        ProcessedUpdatesService::singleton()->flush();
        GridTreeFactory::contentElement($tree['column']);

        $after = self::freshKeysOf($tree);
        foreach ($before as $level => $key) {
            self::assertNotSame($key, $after[$level], $level . ' key must change');
        }
    }

    public function testUnrelatedTreeKeyIsUnaffected(): void
    {
        $treeA = GridTreeFactory::containerTree($this->objFromFixture(Page::class, 'test_page'));
        $treeB = GridTreeFactory::containerTree($this->objFromFixture(Page::class, 'test_page_2'));
        $before = self::freshKeysOf($treeB);

        // Without the flush the key below would be unchanged for the wrong reason.
        ProcessedUpdatesService::singleton()->flush();
        GridTreeFactory::contentElement($treeA['column']);

        self::assertSame($before, self::freshKeysOf($treeB));
    }
}
