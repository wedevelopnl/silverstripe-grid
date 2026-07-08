<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Dev;

use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\E2e\Fixtures\FixtureLoader;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;

/**
 * Guards the grid's `_config/dev.yml` `config_overrides` wiring: loading a
 * fixture through the silverstripe-e2e FixtureLoader must force
 * `auto_scaffold = false` on Section and Row for the fixture write, so the
 * parent-first YAML cannot produce duplicate auto-scaffolded children.
 *
 * The suppression logic lives in the module (FixtureLoader::applyConfigOverrides);
 * this test asserts the grid has wired it correctly, so a broken or missing
 * `config_overrides` block fails here — not just the module in isolation.
 */
#[CoversNothing]
final class FixtureScaffoldSuppressionTest extends SapphireTest
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
