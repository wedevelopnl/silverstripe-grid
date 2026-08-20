<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
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
    protected static $extra_dataobjects = [TestPage::class];

    private LegacyPageDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->discovery = new LegacyPageDiscovery();
    }

    public function testGetEligiblePagesReturnsGridEnabledPages(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $this->seeder->seedPage((int) $page->ID, 100);

        $result = $this->discovery->getEligiblePages('draft');

        self::assertCount(1, $result);
        self::assertSame((int) $page->ID, $result[0]['pageId']);
        self::assertSame(100, $result[0]['areaId']);
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
}
