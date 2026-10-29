<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Page;
use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\VirtualPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\SkippedPage;
use WeDevelop\Grid\Migration\Service\LegacyPageDiscovery;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTestCase;

/**
 * Sole owner of the page-discovery behaviour (eligible set, grid-disabled set,
 * stage/table scanning). {@see LegacyDataReaderTest} keeps only a facade smoke
 * test per one-line delegation.
 */
#[CoversClass(LegacyPageDiscovery::class)]
final class LegacyPageDiscoveryTest extends MigrationTestCase
{
    // Issues seeder DDL inside test bodies. MySQL commits DDL implicitly, so
    // the per-test rollback cannot be trusted here; keep the per-test rebuild.
    protected $usesTransactions = false;

    protected static $extra_dataobjects = [TestPage::class];

    private LegacyPageDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->discovery = new LegacyPageDiscovery();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeLocalisedAreaTables();
        parent::tearDown();
    }

    public function testGetEligiblePagesReturnsGridEnabledPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100);

        $result = $this->discovery->getEligiblePages('draft');

        self::assertCount(1, $result);
        self::assertSame((int) $page->ID, $result[0]['pageId']);
        self::assertSame(100, $result[0]['areaId']);
        self::assertSame(100, $result[0]['liveAreaId'], 'an area shared by every locale is one area on both stages');
        self::assertSame(Page::class, $result[0]['pageClassName']);
    }

    public function testGetEligiblePagesSkipsDisabledPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100, useGrid: false);

        $result = $this->discovery->getEligiblePages('draft');

        self::assertSame([], $result);
    }

    public function testGetEligiblePagesWithPageIdFilter(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $pageId = (int) $page->ID;

        $this->seeder->seedPage($pageId, 100);

        $result = $this->discovery->getEligiblePages('draft', [$pageId]);
        self::assertCount(1, $result);

        $result = $this->discovery->getEligiblePages('draft', [999999]);
        self::assertSame([], $result);
    }

    public function testGetEligiblePagesReturnsEveryEligiblePageNotOnlyTheFirst(): void
    {
        $first = $this->objFromFixture(Page::class, 'test_page');
        $second = $this->objFromFixture(Page::class, 'test_page_2');

        $this->seeder->seedPage((int) $first->ID, 100);
        $this->seeder->seedPage((int) $second->ID, 101);

        $result = $this->discovery->getEligiblePages('draft');

        self::assertCount(2, $result);
    }

    public function testGetPagesWithGridDisabledReturnsEveryDisabledPageNotOnlyTheFirst(): void
    {
        $first = $this->objFromFixture(Page::class, 'test_page');
        $second = $this->objFromFixture(Page::class, 'test_page_2');

        $this->seeder->seedPage((int) $first->ID, 100, useGrid: false);
        $this->seeder->seedPage((int) $second->ID, 101, useGrid: false);

        $result = $this->discovery->getPagesWithGridDisabled('draft');
        $pageIds = \array_column($result, 'pageId');

        self::assertContains((int) $first->ID, $pageIds);
        self::assertContains((int) $second->ID, $pageIds);
    }

    public function testGetEligiblePagesReadsTheLiveTablesForTheLiveStage(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->publishSingle();

        // Only the _Live row is made eligible. A reader that queried the base table
        // (or a mangled "_LivePage") could not produce this result.
        $this->seeder->seedPageOnTable('Page_Live', (int) $page->ID, 200);

        $live = $this->discovery->getEligiblePages('live');
        self::assertCount(1, $live);
        self::assertSame((int) $page->ID, $live[0]['pageId']);
        self::assertSame(200, $live[0]['areaId']);

        self::assertSame([], $this->discovery->getEligiblePages('draft'), 'the draft row was never made eligible');
    }

    public function testStageNameIsCaseInsensitive(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100);

        $result = $this->discovery->getEligiblePages('DRAFT');

        self::assertCount(1, $result);
    }

    public function testGetEligiblePagesScansEveryPageTableIncludingSiteTreeItself(): void
    {
        $viaSiteTree = $this->objFromFixture(Page::class, 'test_page');
        $viaPage = $this->objFromFixture(Page::class, 'test_page_2');

        // The legacy extension could be applied at any level of the SiteTree hierarchy,
        // including SiteTree itself. Both carrying tables must be scanned: dropping the
        // base class, or keeping only the first table found, loses one of these pages.
        $this->seeder->addExtensionColumns('SiteTree');

        try {
            $this->seeder->seedPageOnTable('SiteTree', (int) $viaSiteTree->ID, 100);
            $this->seeder->seedPage((int) $viaPage->ID, 101);

            $result = $this->discovery->getEligiblePages('draft');
            $pageIds = \array_column($result, 'pageId');

            self::assertCount(2, $result);
            self::assertContains((int) $viaSiteTree->ID, $pageIds);
            self::assertContains((int) $viaPage->ID, $pageIds);
        } finally {
            $this->seeder->removeExtensionColumns('SiteTree');
        }
    }

    public function testGetEligiblePagesHonoursUseElementalGridOnTheSiteTreeTable(): void
    {
        $enabled = $this->objFromFixture(Page::class, 'test_page');
        $disabled = $this->objFromFixture(Page::class, 'test_page_2');

        $this->seeder->addExtensionColumns('SiteTree');

        try {
            $this->seeder->seedPageOnTable('SiteTree', (int) $enabled->ID, 100, useGrid: true);
            $this->seeder->seedPageOnTable('SiteTree', (int) $disabled->ID, 101, useGrid: false);

            $pageIds = \array_column($this->discovery->getEligiblePages('draft'), 'pageId');

            self::assertContains((int) $enabled->ID, $pageIds);
            self::assertNotContains((int) $disabled->ID, $pageIds, 'the opt-out flag must filter the SiteTree query too');
        } finally {
            $this->seeder->removeExtensionColumns('SiteTree');
        }
    }

    public function testAPageConvertedToATypeOutsideTheAncestryOfItsAreaTableIsSkipped(): void
    {
        $table = DataObject::getSchema()->tableName(TestPage::class);
        $this->seeder->addElementalAreaColumn($table);

        try {
            $pageId = (int) TestPage::create(['Title' => 'Our team'])->write();
            $this->seeder->seedPlainElementalPageOnTable($table, $pageId, 500);
            $this->seeder->changePageClass($pageId, Page::class);

            self::assertSame([], $this->discovery->getEligiblePages('draft'));
            self::assertEquals(
                [new SkippedPage(
                    $pageId,
                    'Our team',
                    Page::class,
                    'its current type no longer has the old content blocks (they belong to TestPage)',
                )],
                $this->discovery->getSkippedPages(),
            );
        } finally {
            $this->seeder->removeElementalAreaColumn($table);
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function neverUpgradedPageClasses(): iterable
    {
        yield 'redirector page' => [RedirectorPage::class];
        yield 'virtual page' => [VirtualPage::class];
    }

    /**
     * @param class-string $pageClass
     */
    #[DataProvider('neverUpgradedPageClasses')]
    public function testAPageConvertedToANeverUpgradedTypeIsSkippedThoughItsAncestryHoldsTheArea(string $pageClass): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->changePageClass($pageId, $pageClass);

        self::assertSame([], $this->discovery->getEligiblePages('draft'));
        self::assertEquals(
            [new SkippedPage($pageId, 'Integration Test Page', $pageClass, 'this page type is never upgraded')],
            $this->discovery->getSkippedPages(),
        );
    }

    public function testANeverUpgradedTypeIsTheReasonEvenWhenTheAncestryMissesTheAreaTableToo(): void
    {
        $table = DataObject::getSchema()->tableName(TestPage::class);
        $this->seeder->addElementalAreaColumn($table);

        try {
            $pageId = (int) TestPage::create(['Title' => 'Summer offers'])->write();
            $this->seeder->seedPlainElementalPageOnTable($table, $pageId, 500);
            $this->seeder->changePageClass($pageId, RedirectorPage::class);

            self::assertSame(
                ['this page type is never upgraded'],
                \array_map(static fn (SkippedPage $page): string => $page->reason, $this->discovery->getSkippedPages()),
            );
        } finally {
            $this->seeder->removeElementalAreaColumn($table);
        }
    }

    public function testASiteCanAddAPageTypeOfItsOwnToTheTypesNeverUpgraded(): void
    {
        LegacyPageDiscovery::config()->merge('excluded_page_classes', [TestPage::class]);
        $kept = $this->pageId();
        $excluded = (int) TestPage::create(['Title' => 'Search results'])->write();
        $this->seeder->seedPage($kept, 100);
        $this->seeder->seedPage($excluded, 101);

        self::assertSame([$kept], \array_column($this->discovery->getEligiblePages('draft'), 'pageId'));
        self::assertEquals(
            [new SkippedPage($excluded, 'Search results', TestPage::class, 'this page type is never upgraded')],
            $this->discovery->getSkippedPages(),
        );
    }

    public function testAPageConvertedBetweenTwoTypesSharingTheAreaTableStaysEligible(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        $this->seeder->changePageClass($pageId, TestPage::class);

        self::assertSame(
            [['pageId' => $pageId, 'areaId' => 100, 'liveAreaId' => 100, 'pageClassName' => TestPage::class]],
            $this->discovery->getEligiblePages('draft'),
        );
        self::assertSame([], $this->discovery->getSkippedPages());
    }

    public function testAStaleRowOfAnOldTypeNeitherShadowsNorListsAPageTheAncestryStillQualifies(): void
    {
        $table = DataObject::getSchema()->tableName(TestPage::class);
        $this->seeder->addElementalAreaColumn($table);

        try {
            $pageId = (int) TestPage::create(['Title' => 'Converted back'])->write();
            $this->seeder->seedPlainElementalPageOnTable($table, $pageId, 500);
            $this->seeder->seedPage($pageId, 100);
            $this->seeder->changePageClass($pageId, Page::class);

            self::assertSame(
                [['pageId' => $pageId, 'areaId' => 100, 'liveAreaId' => 100, 'pageClassName' => Page::class]],
                $this->discovery->getEligiblePages('draft'),
            );
            self::assertSame([], $this->discovery->getSkippedPages());
        } finally {
            $this->seeder->removeElementalAreaColumn($table);
        }
    }

    public function testADeletedPageIsNeitherEligibleNorListedAsSkipped(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 100);
        DB::prepared_query('DELETE FROM "SiteTree" WHERE "ID" = ?', [$pageId]);

        self::assertSame([], $this->discovery->getEligiblePages('draft'));
        self::assertSame([], $this->discovery->getSkippedPages());
    }

    public function testSkippedPagesAreListedInPageOrderAndHonourThePageFilter(): void
    {
        $first = $this->pageId();
        $second = $this->pageId('test_page_2');
        foreach ([$second => 101, $first => 100] as $pageId => $areaId) {
            $this->seeder->seedPage($pageId, $areaId);
            $this->seeder->changePageClass($pageId, RedirectorPage::class);
        }

        $skippedIds = static fn (array $pages): array => \array_map(static fn (SkippedPage $page): int => $page->pageId, $pages);

        self::assertSame([$first, $second], $skippedIds($this->discovery->getSkippedPages()));
        self::assertSame([$second], $skippedIds($this->discovery->getSkippedPages([$second])));
    }

    public function testAPerLocaleAreaTableSkipsAConvertedPageInEveryLocale(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', 200);
        $this->seeder->changePageClass($pageId, RedirectorPage::class);

        self::assertSame([], $this->discovery->getEligiblePagesInLocale(['en_US'], true, true));
        self::assertSame([], $this->discovery->getEligiblePagesInLocale(['nl_NL'], false, false));
        self::assertEquals(
            [new SkippedPage($pageId, 'Integration Test Page', RedirectorPage::class, 'this page type is never upgraded')],
            $this->discovery->getSkippedPagesInLocales([[['en_US'], true, true], [['nl_NL'], false, false]]),
        );
    }

    public function testAPageAnotherLocalesPassQualifiesIsNotSkipped(): void
    {
        $table = DataObject::getSchema()->tableName(TestPage::class);
        $this->seeder->addElementalAreaColumn($table);

        try {
            $pageId = (int) TestPage::create(['Title' => 'Our team'])->write();
            $this->seeder->seedPlainElementalPageOnTable($table, $pageId, 500);
            $this->seeder->changePageClass($pageId, Page::class);
            DB::prepared_query('UPDATE "Page" SET "UseElementalGrid" = 1 WHERE "ID" = ?', [$pageId]);
            $this->seeder->addLocalisedAreaTables('Page');
            $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', 200);

            self::assertNotSame([], $this->discovery->getSkippedPagesInLocales([[['en_US'], true, true]]));
            self::assertSame([], $this->discovery->getSkippedPagesInLocales([[['en_US'], true, true], [['nl_NL'], false, false]]));
        } finally {
            $this->seeder->removeElementalAreaColumn($table);
        }
    }

    public function testAPageSkippedOnlyThroughAnotherLocalesAreaIsListed(): void
    {
        $pageId = $this->pageId();
        DB::prepared_query('UPDATE "Page" SET "UseElementalGrid" = 1 WHERE "ID" = ?', [$pageId]);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', 200);
        $this->seeder->changePageClass($pageId, RedirectorPage::class);

        self::assertSame([], $this->discovery->getSkippedPagesInLocales([[['en_US'], true, true]]));
        self::assertEquals(
            [new SkippedPage($pageId, 'Integration Test Page', RedirectorPage::class, 'this page type is never upgraded')],
            $this->discovery->getSkippedPagesInLocales([[['en_US'], true, true], [['nl_NL'], false, false]]),
        );
    }

    public function testGetEligiblePagesThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid stage "staging", expected "draft" or "live"');
        $this->discovery->getEligiblePages('staging');
    }

    public function testGetPagesWithGridDisabledReturnsDisabledPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100, useGrid: false);

        $result = $this->discovery->getPagesWithGridDisabled('draft');
        $pageIds = \array_column($result, 'pageId');

        self::assertContains((int) $page->ID, $pageIds);
    }

    public function testGetPagesWithGridDisabledExcludesEnabledPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100, useGrid: true);

        $result = $this->discovery->getPagesWithGridDisabled('draft');
        $pageIds = \array_column($result, 'pageId');

        self::assertNotContains((int) $page->ID, $pageIds);
    }

    public function testGetSharedAreasListsEveryAreaUsedByMoreThanOnePageWithAllItsPages(): void
    {
        $first = (int) $this->objFromFixture(Page::class, 'test_page')->ID;
        $second = (int) $this->objFromFixture(Page::class, 'test_page_2')->ID;
        $third = (int) Page::create(['Title' => 'Own area'])->write();
        $this->seeder->seedPage($first, 100);
        $this->seeder->seedPageSharingArea($second, 100);
        $this->seeder->seedPage($third, 200);

        self::assertSame([100 => [$first, $second]], $this->discovery->getSharedAreas());
    }

    public function testGetSharedAreasIsEmptyWhenEveryPageHasItsOwnArea(): void
    {
        $this->seeder->seedPage((int) $this->objFromFixture(Page::class, 'test_page')->ID, 100);
        $this->seeder->seedPage((int) $this->objFromFixture(Page::class, 'test_page_2')->ID, 200);

        self::assertSame([], $this->discovery->getSharedAreas());
    }

    public function testPerLocaleAreaTablesAreThePageTablesWhoseLocalisedCompanionCarriesTheArea(): void
    {
        self::assertSame([], $this->discovery->getPerLocaleAreaTables());

        $this->seeder->addLocalisedAreaTables('Page');

        self::assertSame(['Page'], (new LegacyPageDiscovery())->getPerLocaleAreaTables());
    }

    public function testACompanionWithoutAnAreaColumnDoesNotMakeTheTablePerLocale(): void
    {
        DB::query('CREATE TABLE "Page_Localised" ("ID" int NOT NULL PRIMARY KEY, "RecordID" int NOT NULL, "Locale" varchar(10) NOT NULL)');

        try {
            self::assertSame([], $this->discovery->getPerLocaleAreaTables());
        } finally {
            DB::query('DROP TABLE "Page_Localised"');
        }
    }

    public function testSetAsideAreaTablesNameTheTableWhoseAreaCompanionsDevBuildRenamed(): void
    {
        $this->seeder->addLocalisedAreaTables('Page', '_obsolete_');

        self::assertSame(['Page' => ['Page_Localised', 'Page_Localised_Live']], $this->discovery->getSetAsideAreaTables());
        self::assertSame([], $this->discovery->getPerLocaleAreaTables());
    }

    public function testALiveCompanionStillSetAsideIsReportedOnceTheDraftOneIsBack(): void
    {
        $this->seeder->addLocalisedAreaTables('Page', withLive: false);
        $this->seeder->addLocalisedAreaTables('Page', '_obsolete_');

        self::assertSame(['Page' => ['Page_Localised_Live']], $this->discovery->getSetAsideAreaTables());
    }

    public function testASetAsideCompanionIsHarmlessOnceTheTableCarriesTheAreaAgain(): void
    {
        $this->seeder->addLocalisedAreaTables('Page', '_obsolete_');
        $this->seeder->addLocalisedAreaTables('Page');

        self::assertSame([], $this->discovery->getSetAsideAreaTables());
    }

    public function testEachStageReadsItsAreaFromItsOwnLocalisedTable(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 300, 'live');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', 200);

        self::assertSame(
            [['pageId' => $pageId, 'areaId' => 100, 'liveAreaId' => 300, 'pageClassName' => Page::class]],
            $this->discovery->getEligiblePagesInLocale(['en_US'], true, true),
        );
    }

    public function testALocaleWithoutADraftRowTakesBothStagesFromTheFirstLocaleInItsChainWithOne(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'nl_NL', 200);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 101, 'live');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'fr_FR', 400, 'live');

        self::assertSame(
            [[
                'pageId' => $pageId,
                'areaId' => 100,
                'liveAreaId' => 101,
                'pageClassName' => Page::class,
                'fallback' => 'en_US',
            ]],
            $this->discovery->getEligiblePagesInLocale(['de_DE', 'fr_FR', 'en_US'], false, false),
            'fr_FR has a live row only, so it supplies neither stage',
        );
    }

    public function testALocaleWithItsOwnDraftRowButNoLiveRowHasNoLiveArea(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'de_DE', 300);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100, 'live');

        self::assertSame(
            [['pageId' => $pageId, 'areaId' => 300, 'liveAreaId' => 0, 'pageClassName' => Page::class]],
            $this->discovery->getEligiblePagesInLocale(['de_DE', 'en_US'], false, false),
            'an unpublished translation never borrows its fallback\'s live area',
        );
    }

    public function testTheDefaultLocaleWithItsOwnDraftRowButNoLiveRowIgnoresTheBaseLiveColumn(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        DB::prepared_query('INSERT INTO "Page_Live" ("ID", "UseElementalGrid", "ElementalAreaID") VALUES (?, 1, 901)', [$pageId]);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);

        self::assertSame(
            [['pageId' => $pageId, 'areaId' => 100, 'liveAreaId' => 0, 'pageClassName' => Page::class]],
            $this->discovery->getEligiblePagesInLocale(['en_US'], true, true),
        );
    }

    public function testTheDefaultLocaleWithoutARowTakesBothStagesFromTheBaseColumns(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        DB::prepared_query('INSERT INTO "Page_Live" ("ID", "UseElementalGrid", "ElementalAreaID") VALUES (?, 1, 901)', [$pageId]);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100, 'live');

        self::assertSame(
            [['pageId' => $pageId, 'areaId' => 900, 'liveAreaId' => 901, 'pageClassName' => Page::class]],
            $this->discovery->getEligiblePagesInLocale(['en_US'], true, true),
            'a live row without a draft row does not own the locale',
        );
    }

    public function testAPageWithNoRowAnywhereInTheChainIsLeftOutOfANonDefaultLocale(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);

        self::assertSame([], $this->discovery->getEligiblePagesInLocale(['fr_FR'], false, true));
    }

    public function testTheDefaultLocaleFallsBackToTheBaseColumnOnlyWhenItHasNoRow(): void
    {
        $withoutRow = $this->pageId();
        $withEmptyRow = $this->pageId('test_page_2');
        $this->seeder->seedPage($withoutRow, 900);
        $this->seeder->seedPage($withEmptyRow, 901);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $withEmptyRow, 'en_US', 0);

        self::assertSame(
            [['pageId' => $withoutRow, 'areaId' => 900, 'liveAreaId' => 0, 'pageClassName' => Page::class]],
            $this->discovery->getEligiblePagesInLocale(['en_US'], true, true),
            'a row without an area is the locale\'s own (empty) answer; an unpublished page has no live area',
        );
    }

    public function testARowWithoutAnAreaStopsTheFallbackInANonDefaultLocale(): void
    {
        $pageId = $this->pageId();
        $this->seeder->seedPage($pageId, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $pageId, 'de_DE', 0);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $pageId, 'en_US', 100, 'live');

        self::assertSame(
            [],
            $this->discovery->getEligiblePagesInLocale(['de_DE', 'en_US'], false, false),
            'a row without an area is the locale\'s own (empty) answer, not a reason to borrow en_US',
        );
    }

    public function testThePerLocaleVariantHonoursUseElementalGridAndThePageFilter(): void
    {
        $enabled = $this->pageId();
        $disabled = $this->pageId('test_page_2');
        $this->seeder->seedPage($enabled, 900);
        $this->seeder->seedPage($disabled, 901, useGrid: false);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $enabled, 'nl_NL', 200);
        $this->seeder->seedLocalisedArea('Page', $disabled, 'nl_NL', 201);

        self::assertSame([$enabled], \array_column($this->discovery->getEligiblePagesInLocale(['nl_NL'], false, false), 'pageId'));
        self::assertSame([], $this->discovery->getEligiblePagesInLocale(['nl_NL'], false, false, [999999]));
    }

    public function testATableSharingOneAreaAcrossLocalesJoinsOnlyWhenAsked(): void
    {
        $table = DataObject::getSchema()->tableName(TestPage::class);
        $this->seeder->addElementalAreaColumn($table);

        try {
            $perLocale = $this->pageId();
            $shared = (int) TestPage::create(['Title' => 'Shared area'])->write();
            $this->seeder->seedPage($perLocale, 900);
            $this->seeder->seedPlainElementalPageOnTable($table, $shared, 500);
            $this->seeder->addLocalisedAreaTables('Page');
            $this->seeder->seedLocalisedArea('Page', $perLocale, 'nl_NL', 200);

            self::assertSame(
                [$perLocale],
                \array_column($this->discovery->getEligiblePagesInLocale(['nl_NL'], false, false), 'pageId'),
            );
            self::assertSame(
                [
                    ['pageId' => $perLocale, 'areaId' => 200, 'liveAreaId' => 0, 'pageClassName' => Page::class],
                    ['pageId' => $shared, 'areaId' => 500, 'liveAreaId' => 500, 'pageClassName' => TestPage::class],
                ],
                $this->discovery->getEligiblePagesInLocale(['nl_NL'], false, true),
            );
        } finally {
            $this->seeder->removeElementalAreaColumn($table);
        }
    }

    public function testSharedAreasInALocaleAreTheAreasResolvedForThatLocale(): void
    {
        $first = $this->pageId();
        $second = $this->pageId('test_page_2');
        $this->seeder->seedPage($first, 900);
        $this->seeder->seedPageSharingArea($second, 900);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $first, 'en_US', 100);
        $this->seeder->seedLocalisedArea('Page', $second, 'en_US', 101);
        $this->seeder->seedLocalisedArea('Page', $first, 'nl_NL', 200);
        $this->seeder->seedLocalisedArea('Page', $second, 'nl_NL', 200);

        self::assertSame([], $this->discovery->getSharedAreasInLocale(['en_US'], true, true), 'the base column is not this locale\'s area');
        self::assertSame([200 => [$first, $second]], $this->discovery->getSharedAreasInLocale(['nl_NL'], false, false));
        self::assertSame(
            [],
            $this->discovery->getSharedAreasInLocale(['de_DE', 'en_US'], false, false),
            'a locale borrowing each page\'s own area does not make that area shared',
        );
    }

    public function testALiveAreaTwoPagesPublishedIsSharedEvenWhenTheirDraftAreasDiffer(): void
    {
        $first = $this->pageId();
        $second = $this->pageId('test_page_2');
        $this->seeder->seedPage($first, 900);
        $this->seeder->seedPage($second, 901);
        $this->seeder->addLocalisedAreaTables('Page');
        $this->seeder->seedLocalisedArea('Page', $first, 'nl_NL', 200);
        $this->seeder->seedLocalisedArea('Page', $second, 'nl_NL', 201);
        $this->seeder->seedLocalisedArea('Page', $first, 'nl_NL', 300, 'live');
        $this->seeder->seedLocalisedArea('Page', $second, 'nl_NL', 300, 'live');

        self::assertSame([300 => [$first, $second]], $this->discovery->getSharedAreasInLocale(['nl_NL'], false, false));
    }
}
