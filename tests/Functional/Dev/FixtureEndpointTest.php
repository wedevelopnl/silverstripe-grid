<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Functional\Dev;

use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\URLSegmentFilter;
use WeDevelop\E2e\Fixtures\FixtureLoader;

/**
 * Guards the grid's _config/dev.yml wiring of the silverstripe-e2e module.
 *
 * The module's own test suite covers the FixtureLoader/FixtureController
 * mechanics (error handling, method restrictions, reset allowlisting). These
 * tests only verify what the GRID supplies: the fixture registrations, the
 * fixture_page_classes allowlist, and the extension registration reachable
 * through the real /dev/e2e-fixtures endpoint. CoversNothing because every
 * class exercised here is vendor code — incidental execution of grid models
 * must not count toward grid coverage.
 */
#[CoversNothing]
final class FixtureEndpointTest extends FunctionalTest
{
    protected $usesDatabase = true;

    private const string BASE_URL = '/dev/e2e-fixtures';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
    }

    protected function tearDown(): void
    {
        // Clean up any E2E pages created during tests. This filters on the
        // "e2e-" prefix ALONE — deliberately broader than FixtureLoader::reset(),
        // which also constrains ClassName — because testResetDoesNotArchiveNonFixturePageSharingPrefix()
        // creates a bare SiteTree that reset() (correctly) refuses to touch, and
        // tearDown must still remove it. Do not narrow this to match reset().
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);

            $pages = SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-']);
            foreach ($pages as $page) {
                $page->doArchive();
            }
        });

        parent::tearDown();
    }

    public function testLoadEndpointServesGridFixture(): void
    {
        $response = $this->post(self::BASE_URL . '/load', ['fixture' => 'element-tree']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $json = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($json['success']);
        self::assertSame('element-tree', $json['fixture']);
        self::assertGreaterThan(0, $json['data']['pageId']);
    }

    public function testGridFixturesAreRegistered(): void
    {
        $names = FixtureLoader::create()->getAvailableFixtures();

        self::assertContains('element-tree', $names);
        self::assertContains('empty-page', $names);
        self::assertContains('multi-zone', $names);
    }

    public function testResetRemovesLoadedGridFixtures(): void
    {
        // End-to-end proof that the configured fixture_page_classes allowlist
        // actually matches the pages the grid fixtures create: if it did not,
        // reset() would leave residue behind and this count would stay > 0.
        $this->post(self::BASE_URL . '/load', ['fixture' => 'element-tree']);

        self::assertGreaterThan(
            0,
            SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-'])->count(),
        );

        $this->post(self::BASE_URL . '/reset?confirm=1', []);

        self::assertSame(
            0,
            SiteTree::get()->filter(['URLSegment:StartsWith' => 'e2e-'])->count(),
        );
    }

    public function testResetDoesNotArchiveNonFixturePageSharingPrefix(): void
    {
        // A plain SiteTree (neither Page nor MultiZonePage) that merely shares
        // the "e2e-" URLSegment prefix represents unrelated content on a shared
        // dev DB. reset() must leave it untouched: the configured
        // fixture_page_classes allowlist, not the prefix alone, decides ownership.
        $unrelatedId = Versioned::withVersionedMode(static function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            $page = SiteTree::create();
            $page->Title = 'Unrelated e2e-prefixed page';
            $page->URLSegment = URLSegmentFilter::create()->filter('e2e-unrelated');
            $page->ClassName = SiteTree::class;

            return (int) $page->write();
        });

        self::assertSame(
            SiteTree::class,
            SiteTree::get()->byID($unrelatedId)?->ClassName,
            'Test setup must produce a bare SiteTree, not a Page subclass',
        );

        FixtureLoader::create()->reset();

        self::assertNotNull(
            SiteTree::get()->byID($unrelatedId),
            'reset() must not archive a non-fixture SiteTree that only shares the e2e- prefix',
        );
    }

    public function testFixturePageClassesCoversEveryFixturePageType(): void
    {
        // reset() matches ClassName exactly (SilverStripe's ClassName filter is
        // non-polymorphic), so any SiteTree page type used as a top-level key in
        // a fixture MUST be listed in the FixtureLoader.fixture_page_classes
        // config — or reset() silently leaves those pages behind, polluting a
        // shared dev DB. This guard fails loudly the moment a fixture introduces
        // a new page type that _config/dev.yml does not cover.
        $declared = Config::inst()->get(FixtureLoader::class, 'fixture_page_classes');
        self::assertIsArray($declared, 'fixture_page_classes must be configured');

        $fixtureDir = dirname(__DIR__, 2) . '/E2E/Fixture';
        $files = glob($fixtureDir . '/*.yml');
        self::assertNotFalse($files, sprintf('Could not list fixtures in %s', $fixtureDir));
        self::assertNotEmpty($files, sprintf('Expected at least one fixture in %s', $fixtureDir));

        /** @var array<class-string<SiteTree>, string> $pageTypesInFixtures Page class => first fixture file using it */
        $pageTypesInFixtures = [];
        foreach ($files as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents, sprintf('Could not read fixture %s', $file));

            // Top-level YAML keys (column 0, ending in a colon) are class names.
            preg_match_all('/^([A-Za-z\\\\][A-Za-z0-9_\\\\]*):[ \t]*$/m', $contents, $matches);

            foreach ($matches[1] as $class) {
                if (is_a($class, SiteTree::class, true)) {
                    $pageTypesInFixtures[$class] ??= basename($file);
                }
            }
        }

        self::assertNotEmpty(
            $pageTypesInFixtures,
            'Expected the E2E fixtures to create at least one SiteTree page type',
        );

        foreach ($pageTypesInFixtures as $class => $file) {
            self::assertContains(
                $class,
                $declared,
                sprintf(
                    'Fixture "%s" creates page type %s, which is missing from the '
                    . 'FixtureLoader.fixture_page_classes config. reset() matches ClassName '
                    . 'exactly, so it would silently leave these pages behind. Add %s to '
                    . '_config/dev.yml.',
                    $file,
                    $class,
                    $class,
                ),
            );
        }
    }
}
