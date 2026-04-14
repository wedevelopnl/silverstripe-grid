<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\ReorderService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(ReorderService::class)]
final class ReorderServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private ReorderService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(ReorderService::class);
    }

    public function testSameParentMoveToFront(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        $result = $this->service->reorder($row3, $section, null);

        self::assertTrue($result->isOk());

        // Reload from DB to check persisted Sort values
        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row3->Sort);
        self::assertSame(2, $row1->Sort);
        self::assertSame(3, $row2->Sort);
    }

    public function testSameParentMoveToMiddle(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        // Move row1 after row2
        $result = $this->service->reorder($row1, $section, $row2->ID);

        self::assertTrue($result->isOk());

        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row2->Sort);
        self::assertSame(2, $row1->Sort);
        self::assertSame(3, $row3->Sort);
    }

    public function testSameParentMoveToEnd(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($section);
        $row2 = GridTreeFactory::row($section);
        $row3 = GridTreeFactory::row($section);

        // Move row1 after row3
        $result = $this->service->reorder($row1, $section, $row3->ID);

        self::assertTrue($result->isOk());

        $row1 = GridElement::get()->byID($row1->ID);
        $row2 = GridElement::get()->byID($row2->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row2->Sort);
        self::assertSame(2, $row3->Sort);
        self::assertSame(3, $row1->Sort);
    }

    public function testCrossParentMove(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $rowA = GridTreeFactory::row($sectionA);
        GridTreeFactory::row($sectionB);

        // Move rowA into sectionB at the front
        $result = $this->service->reorder($rowA, $sectionB, null);

        self::assertTrue($result->isOk());

        $rowA = GridElement::get()->byID($rowA->ID);

        self::assertSame((int) $sectionB->ID, $rowA->ParentID);
        self::assertSame(Section::class, $rowA->ParentClass);
    }

    public function testCrossParentMoveSourceGapsClosed(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $sectionA = GridTreeFactory::section($page);
        $sectionB = GridTreeFactory::section($page);
        $row1 = GridTreeFactory::row($sectionA);
        $row2 = GridTreeFactory::row($sectionA);
        $row3 = GridTreeFactory::row($sectionA);

        // Move row2 out of sectionA
        $result = $this->service->reorder($row2, $sectionB, null);

        self::assertTrue($result->isOk());

        // Source section's remaining rows should have contiguous Sort values
        $row1 = GridElement::get()->byID($row1->ID);
        $row3 = GridElement::get()->byID($row3->ID);

        self::assertSame(1, $row1->Sort);
        self::assertSame(2, $row3->Sort);
    }

    public function testZoneScopedReorderForSections(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        $main1 = GridTreeFactory::section($page, zone: 'main');
        $main2 = GridTreeFactory::section($page, zone: 'main');
        $sidebar1 = GridTreeFactory::section($page, zone: 'sidebar');
        $sidebar2 = GridTreeFactory::section($page, zone: 'sidebar');

        // Reorder within 'main' zone: move main1 after main2
        $result = $this->service->reorder($main1, $page, $main2->ID);

        self::assertTrue($result->isOk());

        // Sidebar zone sections should be untouched
        $sidebar1 = GridElement::get()->byID($sidebar1->ID);
        $sidebar2 = GridElement::get()->byID($sidebar2->ID);

        self::assertSame(1, $sidebar1->Sort);
        self::assertSame(2, $sidebar2->Sort);
    }

    public function testInvalidReferenceElementReturnsFail(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Use an ID that does not exist in the target parent
        $result = $this->service->reorder($row, $section, 999999);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }

    public function testValidationFailureReturnsFail(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $originalSort = $row->Sort;

        // Row cannot be placed at page level — validator should reject
        $result = $this->service->reorder($row, $page, null);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());

        // Element should not have been modified
        $reloaded = GridElement::get()->byID($row->ID);
        self::assertSame($originalSort, $reloaded->Sort);
    }

    public function testCrossParentSectionMoveWithZoneFiltering(): void
    {
        $page1 = $this->objFromFixture(SiteTree::class, 'test_page');
        $page2 = $this->objFromFixture(SiteTree::class, 'test_page_2');

        $main1 = GridTreeFactory::section($page1, zone: 'main');
        $main2 = GridTreeFactory::section($page1, zone: 'main');
        $sidebar1 = GridTreeFactory::section($page1, zone: 'sidebar');

        // Move main2 from page1 to page2
        $result = $this->service->reorder($main2, $page2, null);

        self::assertTrue($result->isOk());

        // Reload and verify sort values are unchanged for unaffected elements
        $main1 = GridElement::get()->byID($main1->ID);
        self::assertSame(1, $main1->Sort, 'main1 sort should remain 1');

        $sidebar1 = GridElement::get()->byID($sidebar1->ID);
        self::assertSame(1, $sidebar1->Sort, 'sidebar1 sort should remain 1 (different zone)');
    }

    public function testInvalidReferenceErrorHasTranslationKey(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Use an ID that does not exist in the target parent — triggers AFTER_ELEMENT_NOT_FOUND
        $result = $this->service->reorder($row, $section, 999999);

        self::assertTrue($result->isErr());

        $error = $result->errors()[0];
        self::assertSame(ReorderService::class . '.AFTER_ELEMENT_NOT_FOUND', $error->key);
    }

    public function testWriteFailureDuringPersistPropagatesAsError(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $col1 = GridTreeFactory::column($row);
        $col2 = GridTreeFactory::column($row);

        // Set invalid GridSettings on col1 in memory (width 13 exceeds 12-column grid)
        // Do NOT write — the invalid state only exists in memory
        $col1->setGridSettings(new GridSettings(
            new ViewportConfig(13, 0, true),
            [],
        ));

        // Reorder col1 after col2 — this triggers a write() on col1 with invalid settings
        $result = $this->service->reorder($col1, $row, $col2->ID);

        self::assertTrue($result->isErr(), 'Reorder should fail when element write triggers validation error');
    }
}
