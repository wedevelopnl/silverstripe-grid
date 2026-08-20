<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Psr\Log\NullLogger;
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
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;
use WeDevelop\Grid\Tests\Integration\Support\CleansGridTables;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;

/**
 * Shared lifecycle and read helpers for the per-mode Fluent migration tests.
 *
 * Each legacy localisation model (Isolated, FieldLocalised, None) gets its own
 * concrete subclass so the suite reads as one class per migration case. The
 * common ground — legacy-table DDL seeding, grid-table cleanup, and the
 * FluentState read helpers used to assert per-locale outcomes — lives here so
 * each subclass shows only the behaviour specific to its model.
 *
 * Not named `*Test.php`, so PHPUnit's default suffix-based discovery skips it
 * (it is abstract and would be skipped regardless).
 */
abstract class FluentMigrationTestCase extends SapphireTest
{
    use CleansGridTables;
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/Fixture/migration-locales.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [FluentIsolatedExtension::class],
    ];

    protected $usesTransactions = false;

    protected const string DEFAULT_VIEWPORT = 'MD';
    protected const string ZONE = 'main';
    protected const array VIEWPORT_KEY_MAP = ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'];
    protected const string CONTENT_CLASS = 'DNADesign\\Elemental\\Models\\ElementContent';

    protected LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
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

    protected function pageId(): int
    {
        return (int) $this->objFromFixture(\Page::class, 'test_page')->ID;
    }

    /**
     * Run the locale-aware orchestrator with the default RowPerSection strategy
     * for a single page. Returns the cross-locale failure count.
     */
    protected function runMigration(int $pageId): int
    {
        $mapper = new FieldMapper();
        $strategy = new RowPerSectionStrategy(new ElementGrouper(), $mapper, self::DEFAULT_VIEWPORT, self::VIEWPORT_KEY_MAP);
        $orchestrator = new FluentMigrationOrchestrator(
            new LegacyDataReader(),
            new LegacyLocalisationDetector(),
            $mapper,
            $strategy,
            new NullLogger(),
        );

        return $orchestrator->run(self::DEFAULT_VIEWPORT, self::ZONE, self::VIEWPORT_KEY_MAP, false, [$pageId]);
    }

    /** @return list<string> */
    protected function contentTitlesInLocale(string $localeCode): array
    {
        return FluentState::singleton()->withState(function (FluentState $state) use ($localeCode): array {
            $state->setLocale($localeCode);
            /** @var list<string> $titles */
            $titles = ContentElement::get()->filter(['ParentClass' => Column::class])->column('Title');

            return $titles;
        });
    }

    protected function sectionCountInLocale(int $pageId, string $localeCode): int
    {
        return FluentState::singleton()->withState(function (FluentState $state) use ($pageId, $localeCode): int {
            $state->setLocale($localeCode);

            return Section::get()->filter(['ParentID' => $pageId, 'Zone' => self::ZONE])->count();
        });
    }

    protected function useGridInLocale(int $pageId, string $localeCode): bool
    {
        return FluentState::singleton()->withState(function (FluentState $state) use ($pageId, $localeCode): bool {
            $state->setLocale($localeCode);
            $reloaded = \Page::get()->byID($pageId);

            return $reloaded !== null && (bool) $reloaded->UseGrid;
        });
    }
}
