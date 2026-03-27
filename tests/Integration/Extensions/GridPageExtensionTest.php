<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridPageExtension::class)]
final class GridPageExtensionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    public function testPageHasSectionsRelation(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');

        self::assertInstanceOf(HasManyList::class, $page->Sections());
        self::assertCount(0, $page->Sections());

        GridTreeFactory::section($page);
        GridTreeFactory::section($page);

        // Refresh the relation
        self::assertCount(2, $page->Sections());
    }

    public function testPublishRecursiveCascadesThroughHierarchy(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $page->publishRecursive();

        Versioned::set_stage(Versioned::LIVE);

        $liveSection = Section::get()->byID($section->ID);
        self::assertInstanceOf(Section::class, $liveSection, 'Section should exist on LIVE');

        $liveRow = Row::get()->byID($row->ID);
        self::assertInstanceOf(Row::class, $liveRow, 'Row should exist on LIVE');

        $liveColumn = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $liveColumn, 'Column should exist on LIVE');
    }

    public function testCascadeDeleteRemovesChildren(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $sectionId = (int) $section->ID;
        $rowId = (int) $row->ID;
        $columnId = (int) $column->ID;

        $page->doArchive();

        self::assertNull(Section::get()->byID($sectionId), 'Section should be deleted');
        self::assertNull(Row::get()->byID($rowId), 'Row should be deleted');
        self::assertNull(Column::get()->byID($columnId), 'Column should be deleted');
    }

    public function testCascadeDuplicateCopiesTree(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        GridTreeFactory::column($row);

        $newPage = $page->duplicate();

        self::assertNotSame((int) $page->ID, (int) $newPage->ID);

        $newSections = $newPage->Sections();
        self::assertCount(1, $newSections, 'Duplicated page should have 1 section');

        $newSection = $newSections->first();
        self::assertInstanceOf(Section::class, $newSection);
        self::assertNotSame((int) $section->ID, (int) $newSection->ID, 'Duplicated section should have a new ID');

        $newRows = $newSection->Rows();
        self::assertCount(1, $newRows, 'Duplicated section should have 1 row');

        $newRow = $newRows->first();
        self::assertInstanceOf(Row::class, $newRow);

        $newColumns = $newRow->Columns();
        self::assertCount(1, $newColumns, 'Duplicated row should have 1 column');
    }

    public function testCascadeDuplicatePreservesGridSettings(): void
    {
        $this->logInWithPermission('ADMIN');

        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $originalSettings = new GridSettings(
            new ViewportConfig(8, 2, true),
            ['lg' => new ViewportConfig(6, 1, false)],
        );
        GridTreeFactory::column($row, gridSettings: $originalSettings);

        $newPage = $page->duplicate();

        $newColumn = $newPage->Sections()->first()->Rows()->first()->Columns()->first();
        self::assertInstanceOf(Column::class, $newColumn);

        $settings = $newColumn->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
        self::assertArrayHasKey('lg', $settings->overrides);
        self::assertSame(6, $settings->overrides['lg']->width);
        self::assertSame(1, $settings->overrides['lg']->offset);
        self::assertFalse($settings->overrides['lg']->visible);
    }
}
