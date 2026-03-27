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

        // Row cannot be placed at page level — validator should reject
        $result = $this->service->reorder($row, $page, null);

        self::assertTrue($result->isErr());
        self::assertNotEmpty($result->errors());
    }
}
