<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;

#[CoversClass(Section::class)]
final class SectionTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    public function testAutoScaffoldCreatesRowAndColumn(): void
    {
        $this->enableAutoScaffolding();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $rows = $section->Rows();
        self::assertCount(1, $rows);

        /** @var Row $row */
        $row = $rows->first();
        self::assertInstanceOf(Row::class, $row);

        $columns = $row->Columns();
        self::assertCount(1, $columns);
        self::assertInstanceOf(Column::class, $columns->first());
    }

    public function testAutoScaffoldIdempotent(): void
    {
        $this->enableAutoScaffolding();

        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertCount(1, $section->Rows());

        // Write again — should not create another Row
        $section->Title = 'Updated';
        $section->write();

        // Refresh the relation
        self::assertCount(1, $section->Rows());
    }

    public function testAutoScaffoldDisabledViaConfig(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertCount(0, $section->Rows());
    }

    public function testAutoScaffoldSkippedOnLiveStage(): void
    {
        // Section scaffolding stays ON so the LIVE-stage guard is what prevents it
        Config::modify()->set(Section::class, 'auto_scaffold', true);

        $page = $this->objFromFixture(Page::class, 'test_page');

        Versioned::set_stage(Versioned::LIVE);

        $section = Section::create();
        $section->Zone = 'main';
        $section->ParentID = $page->ID;
        $section->ParentClass = $page::class;
        $section->write();

        self::assertCount(0, $section->Rows());
    }

    public function testAutoScaffoldSkippedWhenChildrenExist(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        self::assertCount(1, $section->Rows());

        // Re-enable scaffolding and write again
        Config::modify()->set(Section::class, 'auto_scaffold', true);
        $section->Title = 'Updated';
        $section->write();

        // Still only 1 row — scaffold guard sees existing children
        self::assertCount(1, $section->Rows());
        self::assertSame((int) $row->ID, (int) $section->Rows()->first()->ID);
    }

    public function testEnsureSortSetFiltersByZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $main1 = GridTreeFactory::section($page, zone: 'main');
        $main2 = GridTreeFactory::section($page, zone: 'main');
        $sidebar1 = GridTreeFactory::section($page, zone: 'sidebar');

        self::assertSame(1, $main1->Sort);
        self::assertSame(2, $main2->Sort);
        // Sidebar zone has independent Sort numbering
        self::assertSame(1, $sidebar1->Sort);

        // Reload from DB to confirm persisted values match
        $main1Reloaded = Section::get()->byID($main1->ID);
        $main2Reloaded = Section::get()->byID($main2->ID);
        $sidebar1Reloaded = Section::get()->byID($sidebar1->ID);

        self::assertSame(1, $main1Reloaded->Sort);
        self::assertSame(2, $main2Reloaded->Sort);
        self::assertSame(1, $sidebar1Reloaded->Sort);
    }

    public function testEnsureSortSetPreservesExplicitSort(): void
    {
        // Pins the `if ($this->Sort > 0) return;` guard in Section::ensureSortSet.
        // Removing it would overwrite the explicit Sort with the computed max+1 (=1
        // for the first section in the zone), losing the caller-supplied value.
        $page = $this->objFromFixture(Page::class, 'test_page');

        $section = GridTreeFactory::section($page, zone: 'main', sort: 5);

        self::assertSame(5, $section->Sort);

        $reloaded = Section::get()->byID($section->ID);
        self::assertSame(5, $reloaded->Sort, 'explicit Sort must survive the write, not be reassigned');
    }

    public function testEnsureSortSetFiltersByParentPage(): void
    {
        // Pins the `'ParentID' => $this->ParentID` filter in Section::ensureSortSet.
        // Without it, Sort would be computed across ALL pages' sections in the same zone.
        $pageA = $this->objFromFixture(Page::class, 'test_page');
        $pageB = $this->objFromFixture(Page::class, 'test_page_2');

        // Page A already has 3 sections in 'main'
        GridTreeFactory::section($pageA, zone: 'main');
        GridTreeFactory::section($pageA, zone: 'main');
        GridTreeFactory::section($pageA, zone: 'main');

        // First section on Page B (same zone) must get Sort=1, not 4
        $pageBFirst = GridTreeFactory::section($pageB, zone: 'main');

        self::assertSame(
            1,
            (int) $pageBFirst->Sort,
            'Page B section Sort must start fresh; removing ParentID from filter would return 4',
        );
    }

    public function testGetChildrenReturnsRows(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $children = $section->getChildren();
        self::assertCount(1, $children);
        self::assertSame((int) $row->ID, (int) $children->first()->ID);
    }

    public function testHasChildrenReturnsTrueWhenRowsExist(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        GridTreeFactory::row($section);

        self::assertTrue($section->hasChildren());
    }

    public function testHasChildrenReturnsFalseWhenEmpty(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertFalse($section->hasChildren());
    }

    public function testGetChildCountSummary(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        self::assertSame('0 rows', $section->getChildCountSummary());

        GridTreeFactory::row($section);
        self::assertSame('1 row', $section->getChildCountSummary());

        GridTreeFactory::row($section);
        self::assertSame('2 rows', $section->getChildCountSummary());
    }

    public function testGetContainerType(): void
    {
        self::assertSame(ContainerType::Section, Section::singleton()->getContainerType());
    }

    public function testGetContainerClasses(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);

        $classes = $section->getContainerClasses();

        self::assertNotEmpty($classes);
    }

    public function testGetContainerClassesFluid(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        Config::modify()->set(Section::class, 'fluid_container', true);
        $fluidSection = GridTreeFactory::section($page);
        $fluidClasses = $fluidSection->getContainerClasses();

        Config::modify()->set(Section::class, 'fluid_container', false);
        $fixedSection = GridTreeFactory::section($page);
        $fixedClasses = $fixedSection->getContainerClasses();

        self::assertNotEmpty($fluidClasses);
        self::assertNotEmpty($fixedClasses);
        self::assertNotSame($fluidClasses, $fixedClasses);
    }

}
