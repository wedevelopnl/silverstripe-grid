<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\Grid\Dev\FixtureScaffoldSuppressionExtension;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Exercises the extension through the silverstripe-e2e FixtureLoader as wired
 * in _config/dev.yml, so a broken `extensions:` registration fails here too —
 * not just the extension class in isolation.
 */
#[CoversClass(FixtureScaffoldSuppressionExtension::class)]
final class FixtureScaffoldSuppressionExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    public function testLoadSuppressesAutoScaffolding(): void
    {
        $result = FixtureLoader::create()->load('element-tree');

        $section = Section::get()->filter('ParentID', $result->pageId)->first();
        self::assertNotNull($section);

        // ElementTree.yml defines 1 row under section1 — auto-scaffold would double it
        self::assertCount(1, $section->getChildren());
    }

    public function testLoadCreatesFixtureDefinedHierarchy(): void
    {
        $result = FixtureLoader::create()->load('element-tree');

        self::assertArrayHasKey(Section::class, $result->fixtureMap);
        self::assertArrayHasKey(Row::class, $result->fixtureMap);
    }
}
