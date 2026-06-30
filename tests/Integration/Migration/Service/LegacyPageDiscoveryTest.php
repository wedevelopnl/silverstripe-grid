<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\Service\LegacyPageDiscovery;
use WeDevelop\Grid\Tests\Integration\Migration\Support\LegacyTableSeeder;

/**
 * Direct surface tests for the discovery half of the split reader.
 *
 * The exhaustive page-discovery behaviour is exercised through the facade by
 * {@see LegacyDataReaderTest}; these assertions pin the same eligible-set and
 * grid-disabled results when calling {@see LegacyPageDiscovery} directly.
 */
#[CoversClass(LegacyPageDiscovery::class)]
final class LegacyPageDiscoveryTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../../Fixture/page.yml';

    protected static $extra_dataobjects = [TestPage::class];

    private LegacyPageDiscovery $discovery;

    private LegacyTableSeeder $seeder;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        $this->discovery = new LegacyPageDiscovery();
        $this->seeder = new LegacyTableSeeder();
        $this->seeder->createTables();
        $this->seeder->addExtensionColumns('Page');
        $this->seeder->truncateTables();
    }

    protected function tearDown(): void
    {
        $this->seeder->removeExtensionColumns('Page');
        $this->seeder->dropTables();

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

    public function testGetEligiblePagesThrowsOnInvalidStage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
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
