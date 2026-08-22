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
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;

/**
 * Guards the grid's _config/dev.yml wiring of the silverstripe-e2e module.
 *
 * The module's own test suite covers the FixtureLoader/FixtureController
 * mechanics (error handling, method restrictions, the purge itself). These
 * tests only verify what the GRID supplies: the fixture registrations, the
 * purge scope those fixtures need, and the extension registration reachable
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
        // reset() purges the declared classes, so only records OUTSIDE that
        // scope survive a test — the bare SiteTree below is the one case.
        Versioned::withVersionedMode(static function (): void {
            Versioned::set_stage(Versioned::DRAFT);

            foreach (SiteTree::get()->filter(['ClassName' => SiteTree::class]) as $page) {
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
        // End-to-end proof that the declared purge_classes actually cover what
        // the grid fixtures write: a class missing from the scope would leave
        // its records behind and one of these counts would stay above 0.
        $this->post(self::BASE_URL . '/load', ['fixture' => 'element-tree']);

        self::assertGreaterThan(0, SiteTree::get()->count());
        self::assertGreaterThan(0, Section::get()->count());

        $this->post(self::BASE_URL . '/reset?confirm=1', []);

        self::assertSame(0, SiteTree::get()->count());
        self::assertSame(0, Section::get()->count());
    }

    public function testResetLeavesRecordsOfAClassTheConfigDoesNotDeclare(): void
    {
        // The scope is `Page` and its subclasses, not SiteTree: a page type
        // outside the declared classes is not the E2E database's to delete.
        // Narrowing or widening that entry is a deliberate act, not a detail.
        $unrelatedId = Versioned::withVersionedMode(static function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            $page = SiteTree::create();
            $page->Title = 'Unrelated page';
            $page->URLSegment = URLSegmentFilter::create()->filter('unrelated-page');
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
            'reset() must not delete a class the purge scope does not declare',
        );
    }

    public function testResetSweepsEverySharedBlockIncludingOnesAPageStillPlaces(): void
    {
        // A SharedBlock has no page and no URL, so nothing that infers ownership
        // from reachability ever collected one: every fixture load and every
        // convert-to-shared spec left another behind. Declaring the class sweeps
        // them whatever holds them — including a block placed on live content,
        // which is the cost of pointing the suite at this database.
        $ids = Versioned::withVersionedMode(static function (): array {
            Versioned::set_stage(Versioned::DRAFT);

            $orphan = SharedBlock::create();
            $orphan->Title = 'Orphaned by an earlier run';
            $orphanId = (int) $orphan->write();

            $placed = SharedBlock::create();
            $placed->Title = 'Placed on a page';
            $placedId = (int) $placed->write();

            // A reference to an empty block is refused by hierarchy validation,
            // so the block needs the root that decides where it may be placed.
            $root = Section::create();
            $root->Title = 'Block root';
            $root->ParentID = $placedId;
            $root->ParentClass = SharedBlock::class;
            $root->write();

            $page = SiteTree::create();
            $page->Title = 'Consuming page';
            $page->URLSegment = URLSegmentFilter::create()->filter('consuming-page');
            $page->ClassName = 'Page';
            $pageId = (int) $page->write();

            $reference = SharedBlockReference::create();
            $reference->BlockID = $placedId;
            $reference->ParentID = $pageId;
            $reference->ParentClass = SiteTree::class;
            $reference->Zone = 'main';
            $reference->write();

            return ['orphan' => $orphanId, 'placed' => $placedId];
        });

        FixtureLoader::create()->reset();

        self::assertNull(SharedBlock::get()->byID($ids['orphan']));
        self::assertNull(SharedBlock::get()->byID($ids['placed']));
    }

    public function testResetSweepsAPlacementWhosePageIsGone(): void
    {
        // Debris, not content: archiving a page leaves its placement rows
        // behind, and a placement with no page says nothing about where a block
        // sits. Nothing reaches these from a fixture's own records — the
        // GridElement entry in purge_classes is what collects them.
        $referenceId = Versioned::withVersionedMode(static function (): int {
            Versioned::set_stage(Versioned::DRAFT);

            $block = SharedBlock::create();
            $block->Title = 'Held by debris';
            $blockId = (int) $block->write();

            $root = Section::create();
            $root->Title = 'Block root';
            $root->ParentID = $blockId;
            $root->ParentClass = SharedBlock::class;
            $root->write();

            // A placement pointing at a page id that does not exist.
            $reference = SharedBlockReference::create();
            $reference->BlockID = $blockId;
            $reference->ParentID = 999_999;
            $reference->ParentClass = SiteTree::class;
            $reference->Zone = 'main';

            return (int) $reference->write();
        });

        FixtureLoader::create()->reset();

        self::assertNull(
            SharedBlockReference::get()->byID($referenceId),
            'reset() must sweep a placement whose page is gone',
        );
    }

    public function testPurgeClassesCoversEveryFixturePageType(): void
    {
        // reset() only deletes what purge_classes declares, so any SiteTree page
        // type used as a top-level key in a fixture must fall under one of those
        // classes — or its pages survive every reset and pollute the dev DB. The
        // match is polymorphic (unlike the 0.1.x ClassName allowlist this
        // replaced), so `Page` covers App\MultiZonePage; a page type descending
        // straight from SiteTree would not, and fails here.
        $declared = Config::inst()->get(FixtureLoader::class, 'purge_classes');
        self::assertIsArray($declared, 'purge_classes must be configured');

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
            $covered = false;
            foreach ($declared as $purgeClass) {
                if (is_a($class, $purgeClass, true)) {
                    $covered = true;

                    break;
                }
            }

            self::assertTrue(
                $covered,
                sprintf(
                    'Fixture "%s" creates page type %s, which no entry in the '
                    . 'FixtureLoader.purge_classes config covers. reset() would leave those '
                    . 'pages behind. Add %s (or a parent of it) to _config/dev.yml.',
                    $file,
                    $class,
                    $class,
                ),
            );
        }
    }
}
