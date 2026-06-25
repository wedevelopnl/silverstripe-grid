<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\FluentMigrationOrchestrator;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Service\LegacyLocalisationDetector;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

#[CoversClass(FluentMigrationOrchestrator::class)]
final class FluentGridMigrationTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/Fixture/migration-locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected $usesTransactions = false;

    private const string DEFAULT_VIEWPORT = 'MD';
    private const string ZONE = 'main';
    private const array VIEWPORT_KEY_MAP = ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'];
    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
        Locale::clearCached();

        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();
        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeFieldLocalisedTables();
        $this->seeder->removeLocaleIdColumn();
        $this->seeder->dropTables();
        parent::tearDown();
    }

    private function cleanGridTables(): void
    {
        // Mirror GridMigrationServiceTest::cleanGridTables(): delete grid ORM rows
        // (draft + live) so DDL-committed rows from prior tests do not leak.
        // Grid ORM tables are namespaced (WeDevelop_Grid_*), not bare "Section"
        // etc.; each DELETE is guarded by an existence check because the table
        // set present depends on which extra_dataobjects a run registers.
        $tables = [
            'WeDevelop_Grid_ContentElement', 'WeDevelop_Grid_ContentElement_Live',
            'WeDevelop_Grid_Column', 'WeDevelop_Grid_Column_Live',
            'WeDevelop_Grid_Row', 'WeDevelop_Grid_Row_Live',
            'WeDevelop_Grid_Section', 'WeDevelop_Grid_Section_Live',
            'WeDevelop_Grid_GridElement', 'WeDevelop_Grid_GridElement_Live',
        ];

        $allTables = \SilverStripe\ORM\DB::table_list();

        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                \SilverStripe\ORM\DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }

    private function runMigration(int $pageId): int
    {
        $reader = new LegacyDataReader();
        $detector = new LegacyLocalisationDetector();
        $mapper = new FieldMapper();
        $strategy = new RowPerSectionStrategy(new ElementGrouper(), $mapper, self::DEFAULT_VIEWPORT, self::VIEWPORT_KEY_MAP);
        $orchestrator = new FluentMigrationOrchestrator($reader, $detector, $mapper, $strategy, new NullLogger());

        return $orchestrator->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$pageId]);
    }

    /** @return list<string> */
    private function contentTitlesInLocale(string $localeCode): array
    {
        return FluentState::singleton()->withState(function (FluentState $state) use ($localeCode): array {
            $state->setLocale($localeCode);
            /** @var list<string> $titles */
            $titles = ContentElement::get()->filter(['ParentClass' => Column::class])->column('Title');

            return $titles;
        });
    }

    public function testFieldLocalisedMigratesPerLocaleWithOverlay(): void
    {
        $page = $this->objFromFixture(\Page::class, 'test_page');
        $pageId = (int) $page->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN One', 'SizeMD' => 6]);
        $this->seeder->seedContentMedia(7000, ['HTML' => '<p>EN</p>']);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement(7000, 'nl_NL', ['Title' => 'NL One']);
        $this->seeder->seedLocalisedContent(7000, 'nl_NL', ['HTML' => '<p>NL</p>']);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['EN One'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['NL One'], $this->contentTitlesInLocale('nl_NL'), 'nl locale gets the overlaid title');
    }

    public function testIsolatedMigratesEachLocaleIndependently(): void
    {
        $page = $this->objFromFixture(\Page::class, 'test_page');
        $pageId = (int) $page->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->addLocaleIdColumn();
        $en = (int) $this->objFromFixture(Locale::class, 'en')->ID;
        $nl = (int) $this->objFromFixture(Locale::class, 'nl')->ID;
        $this->seeder->seedElement(7100, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN Iso', 'LocaleID' => $en]);
        $this->seeder->seedContentMedia(7100);
        $this->seeder->seedElement(7101, 100, self::CONTENT_CLASS, 2, ['Title' => 'NL Iso', 'LocaleID' => $nl]);
        $this->seeder->seedContentMedia(7101);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['EN Iso'], $this->contentTitlesInLocale('en_US'));
        self::assertSame(['NL Iso'], $this->contentTitlesInLocale('nl_NL'));
    }

    public function testNoneMigratesOnlyDefaultLocale(): void
    {
        $page = $this->objFromFixture(\Page::class, 'test_page');
        $pageId = (int) $page->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7200, 100, self::CONTENT_CLASS, 1, ['Title' => 'Shared']);
        $this->seeder->seedContentMedia(7200);

        $failures = $this->runMigration($pageId);

        self::assertSame(0, $failures);
        self::assertSame(['Shared'], $this->contentTitlesInLocale('en_US'));
        self::assertSame([], $this->contentTitlesInLocale('nl_NL'), 'non-localised content is not duplicated into other locales');
    }

    public function testReRunIsIdempotentPerLocale(): void
    {
        $page = $this->objFromFixture(\Page::class, 'test_page');
        $pageId = (int) $page->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7300, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN One', 'SizeMD' => 6]);
        $this->seeder->seedContentMedia(7300, ['HTML' => '<p>EN</p>']);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement(7300, 'nl_NL', ['Title' => 'NL One']);
        $this->seeder->seedLocalisedContent(7300, 'nl_NL', ['HTML' => '<p>NL</p>']);

        $this->runMigration($pageId);
        $this->runMigration($pageId);

        foreach (['en_US', 'nl_NL'] as $code) {
            $count = FluentState::singleton()->withState(function (FluentState $state) use ($code, $pageId): int {
                $state->setLocale($code);

                return Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->count();
            });
            self::assertSame(1, $count, "no duplicate sections in {$code}");
        }
    }

    public function testUseGridIsSetInEveryLocale(): void
    {
        $page = $this->objFromFixture(\Page::class, 'test_page');
        $pageId = (int) $page->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7400, 100, self::CONTENT_CLASS, 1, ['Title' => 'EN One', 'SizeMD' => 6]);
        $this->seeder->seedContentMedia(7400);
        $this->seeder->addFieldLocalisedTables();
        $this->seeder->seedLocalisedElement(7400, 'nl_NL', ['Title' => 'NL One']);

        $this->runMigration($pageId);

        foreach (['en_US', 'nl_NL'] as $code) {
            $useGrid = FluentState::singleton()->withState(function (FluentState $state) use ($code, $pageId): bool {
                $state->setLocale($code);
                $reloaded = \Page::get()->byID($pageId);

                return $reloaded !== null && (bool) $reloaded->UseGrid;
            });
            self::assertTrue($useGrid, "UseGrid must be set in {$code} (non-localised shared flag)");
        }
    }
}
