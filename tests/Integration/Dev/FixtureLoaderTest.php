<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use InvalidArgumentException;
use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Dev\FixtureLoader;
use WeDevelop\Grid\Dev\FixtureResult;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

#[CoversClass(FixtureLoader::class)]
final class FixtureLoaderTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testLoadCreatesPageAndReturnsResult(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        self::assertInstanceOf(FixtureResult::class, $result);
        self::assertGreaterThan(0, $result->pageId);
        self::assertNotEmpty($result->pageUrl);
        self::assertSame('element-tree', $result->fixtureName);
    }

    public function testLoadCreatesElementHierarchy(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        self::assertArrayHasKey(Section::class, $result->fixtureMap);
        self::assertArrayHasKey(Row::class, $result->fixtureMap);
    }

    public function testLoadSuppressesAutoScaffolding(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        $section = Section::get()->filter('ParentID', $result->pageId)->first();
        self::assertNotNull($section);

        // ElementTree.yml defines 1 row under section1 -- auto-scaffold would double it
        self::assertCount(1, $section->getChildren());
    }

    public function testResetRemovesE2ePages(): void
    {
        $loader = FixtureLoader::create();
        $loader->load('element-tree');

        // Verify at least one E2E page exists
        self::assertGreaterThan(
            0,
            SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-'])->count(),
        );

        $loader->reset();

        self::assertSame(
            0,
            SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-'])->count(),
        );
    }

    public function testResetIsIdempotent(): void
    {
        $loader = FixtureLoader::create();

        // No E2E pages exist yet -- reset should not throw
        $loader->reset();
        $loader->reset();

        self::assertSame(
            0,
            SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-'])->count(),
        );
    }

    public function testGetAvailableFixturesReturnsNames(): void
    {
        $loader = FixtureLoader::create();
        $names = $loader->getAvailableFixtures();

        self::assertContains('element-tree', $names);
        self::assertContains('empty-page', $names);
    }

    public function testLoadThrowsForUnknownFixture(): void
    {
        $loader = FixtureLoader::create();

        $this->expectException(InvalidArgumentException::class);
        $loader->load('nonexistent-fixture');
    }

    public function testLoadAppliesPostActions(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        // element-tree has post_action: publish_recursive on Page.e2e_page
        Versioned::set_stage(Versioned::LIVE);
        self::assertNotNull(SiteTree::get()->byID($result->pageId));
    }

    public function testLoadThrowsForFixtureWithNoPath(): void
    {
        Config::modify()->merge(FixtureLoader::class, 'fixtures', [
            'broken-no-path' => ['post_actions' => []],
        ]);

        $loader = FixtureLoader::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no path configured');
        $loader->load('broken-no-path');
    }

    public function testLoadThrowsForFixtureWithEmptyPath(): void
    {
        Config::modify()->merge(FixtureLoader::class, 'fixtures', [
            'broken-empty-path' => ['path' => ''],
        ]);

        $loader = FixtureLoader::create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no path configured');
        $loader->load('broken-empty-path');
    }

    public function testLoadPrefersSiteTreePageClass(): void
    {
        $loader = FixtureLoader::create();
        $result = $loader->load('element-tree');

        // findPageInFactory() prefers Page::class over other SiteTree subclasses
        $page = SiteTree::get()->byID($result->pageId);
        self::assertNotNull($page);
        self::assertSame(Page::class, $page::class);
    }
}
