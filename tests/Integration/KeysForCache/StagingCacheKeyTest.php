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
 * Guards that draft-only edits do not change the live cache key until publish.
 */
#[CoversNothing]
final class StagingCacheKeyTest extends SapphireTest
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

    public function testDraftEditDoesNotChangeLiveKeyUntilPublish(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $tree = GridTreeFactory::containerTree($page);
        $page->publishRecursive();
        $before = self::liveKeysOf($tree);
        self::assertNotSame('', $before['section']);

        ProcessedUpdatesService::singleton()->flush();
        $content = GridTreeFactory::contentElement($tree['column']);
        self::assertSame($before, self::liveKeysOf($tree), 'Live keys must be stable across draft-only edits');

        ProcessedUpdatesService::singleton()->flush();
        $content->publishRecursive();
        self::assertNotSame($before['section'], self::liveKeysOf($tree)['section'], 'Live key must change after publish');
    }
}
