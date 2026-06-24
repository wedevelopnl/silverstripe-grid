<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use Psr\Log\NullLogger;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\DB;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Verifies that the migration service migrates legacy Elemental content per
 * Fluent locale: the default locale from the base ElementalArea and each other
 * locale from its `<PageTable>_Localised` area, with locale-isolated results.
 */
#[CoversClass(GridMigrationService::class)]
final class FluentGridMigrationTest extends SapphireTest
{
    /** @var array<int, string> */
    protected static $fixture_file = [
        __DIR__ . '/Fixture/locales.yml',
        __DIR__ . '/../Fixture/page.yml',
    ];

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        \WeDevelop\Grid\Model\GridElement::class => [FluentIsolatedExtension::class],
    ];

    // DDL in LegacyTableSeeder auto-commits in MySQL, breaking savepoint nesting.
    protected $usesTransactions = false;

    private const string DEFAULT_VIEWPORT = 'MD';

    private const string ZONE = 'main';

    private const array VIEWPORT_KEY_MAP = [
        'XS' => 'xs',
        'SM' => 'sm',
        'MD' => 'md',
        'LG' => 'lg',
        'XL' => 'xl',
    ];

    private const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    private const string DEFAULT_LOCALE = 'en_US';

    private const string OTHER_LOCALE = 'nl_NL';

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
        $this->seeder->removeLocalisedAreaColumn('Page');
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->dropTables();

        parent::tearDown();
    }

    private function cleanGridTables(): void
    {
        $tables = [
            'WeDevelop_Grid_ContentElement', 'WeDevelop_Grid_ContentElement_Live',
            'WeDevelop_Grid_Column', 'WeDevelop_Grid_Column_Live',
            'WeDevelop_Grid_Row', 'WeDevelop_Grid_Row_Live',
            'WeDevelop_Grid_Section', 'WeDevelop_Grid_Section_Live',
            'WeDevelop_Grid_GridElement', 'WeDevelop_Grid_GridElement_Live',
        ];

        $allTables = DB::table_list();
        foreach ($tables as $table) {
            if (\array_key_exists(\strtolower($table), $allTables)) {
                DB::query("DELETE FROM \"{$table}\"");
            }
        }
    }

    /**
     * @return list<string> Content element titles visible in the given locale (draft stage)
     */
    private function contentTitlesInLocale(string $locale): array
    {
        return FluentState::singleton()->withState(function (FluentState $state) use ($locale): array {
            $state->setLocale($locale);

            /** @var list<string> $titles */
            $titles = ContentElement::get()->filter(['ParentClass' => Column::class])->column('Title');

            return $titles;
        });
    }

    public function testMigratesDefaultAndNonDefaultLocales(): void
    {
        $pageId = (int) $this->objFromFixture(Page::class, 'test_page')->ID;

        // Default locale (en_US) — base area 100
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 1, ['SizeMD' => 6, 'Title' => 'EN One']);
        $this->seeder->seedContentMedia(7000);

        // Dutch (nl_NL) — localised area 200
        $this->seeder->addLocalisedAreaColumn('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, self::OTHER_LOCALE, 200);
        $this->seeder->seedElement(7100, 200, self::CONTENT_CLASS, 1, ['SizeMD' => 4, 'Title' => 'NL One']);
        $this->seeder->seedContentMedia(7100);

        $failures = $this->migrate([$pageId]);
        self::assertSame(0, $failures);

        self::assertSame(['EN One'], $this->contentTitlesInLocale(self::DEFAULT_LOCALE), 'English locale sees only EN content');
        self::assertSame(['NL One'], $this->contentTitlesInLocale(self::OTHER_LOCALE), 'Dutch locale sees only NL content');
    }

    public function testReRunDoesNotDuplicatePerLocale(): void
    {
        $pageId = (int) $this->objFromFixture(Page::class, 'test_page')->ID;

        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7000, 100, self::CONTENT_CLASS, 1, ['SizeMD' => 6, 'Title' => 'EN One']);
        $this->seeder->seedContentMedia(7000);
        $this->seeder->addLocalisedAreaColumn('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, self::OTHER_LOCALE, 200);
        $this->seeder->seedElement(7100, 200, self::CONTENT_CLASS, 1, ['SizeMD' => 4, 'Title' => 'NL One']);
        $this->seeder->seedContentMedia(7100);

        $this->migrate([$pageId]);
        $this->migrate([$pageId]);

        foreach ([self::DEFAULT_LOCALE, self::OTHER_LOCALE] as $locale) {
            $count = FluentState::singleton()->withState(function (FluentState $state) use ($locale): int {
                $state->setLocale($locale);

                return Section::get()->filter(['Zone' => self::ZONE])->count();
            });
            self::assertSame(1, $count, "No duplicate sections in {$locale}");
        }
    }

    public function testNonLocalisedAreaMigratesOnceInDefaultLocale(): void
    {
        // A base area with NO <table>_Localised companion: shared, non-localised.
        $pageId = (int) $this->objFromFixture(Page::class, 'test_page')->ID;
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->seedElement(7200, 100, self::CONTENT_CLASS, 1, ['SizeMD' => 12, 'Title' => 'Shared']);
        $this->seeder->seedContentMedia(7200);

        $this->migrate([$pageId]);

        self::assertSame(['Shared'], $this->contentTitlesInLocale(self::DEFAULT_LOCALE), 'Default locale gets the shared content');
        self::assertSame([], $this->contentTitlesInLocale(self::OTHER_LOCALE), 'A non-localised area is not duplicated into other locales');
    }

    /**
     * @param list<int> $pageIds
     */
    private function migrate(array $pageIds): int
    {
        $mapper = new FieldMapper();
        $strategy = new RowPerSectionStrategy(
            new ElementGrouper(),
            $mapper,
            self::DEFAULT_VIEWPORT,
            self::VIEWPORT_KEY_MAP,
        );
        $service = new GridMigrationService(new LegacyDataReader(), $mapper, $strategy, new NullLogger());

        return $service->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, dryRun: false, pageIds: $pageIds);
    }
}
