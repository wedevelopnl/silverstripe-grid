<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(Column::class)]
final class ColumnTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    // ── GridSettings lifecycle ──────────────────────────────────

    public function testGridSettingsInitializedOnFirstWrite(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Create column without explicit GridSettings — onBeforeWrite should initialize
        $column = GridTreeFactory::column($row);

        // Reload from DB to verify settings were persisted, not just the in-memory fallback
        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        // Verify the composite field was actually written (not relying on getValue fallback)
        self::assertTrue($reloaded->dbObject('GridSettings')->exists());

        $settings = $reloaded->getGridSettings();
        $expected = GridSettings::initial(12);

        self::assertSame($expected->default->width, $settings->default->width);
        self::assertSame($expected->default->offset, $settings->default->offset);
        self::assertSame($expected->default->visible, $settings->default->visible);
        self::assertSame([], $settings->overrides);
    }

    public function testGridSettingsPreservedOnSubsequentWrite(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $custom = GridSettings::initial(12)->withOverride('md', new ViewportConfig(6, 2, false));
        $column = GridTreeFactory::column($row, gridSettings: $custom);

        // Write again
        $column->Title = 'Updated';
        $column->write();

        $settings = $column->getGridSettings();
        self::assertSame(6, $settings->overrides['md']->width);
        self::assertSame(2, $settings->overrides['md']->offset);
        self::assertFalse($settings->overrides['md']->visible);
    }

    public function testGetGridSettingsRoundTrip(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $original = GridSettings::initial(12)->withOverride('md', new ViewportConfig(6, 2, false));
        $column = GridTreeFactory::column($row, gridSettings: $original);

        // Reload from DB
        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame($original->default->width, $settings->default->width);
        self::assertSame($original->default->offset, $settings->default->offset);
        self::assertSame($original->default->visible, $settings->default->visible);
        self::assertArrayHasKey('md', $settings->overrides);
        self::assertSame(6, $settings->overrides['md']->width);
    }

    public function testSetGridSettingsWithJsonString(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $json = '{"default":{"width":8,"offset":1,"visible":true},"overrides":{}}';
        $column->setGridSettings($json);
        $column->write();

        $reloaded = Column::get()->byID($column->ID);
        $settings = $reloaded->getGridSettings();

        self::assertSame(8, $settings->default->width);
        self::assertSame(1, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    public function testGetGridSettingsReturnsInitialWhenNoData(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Create a Column object but don't write it — dbObject has no data
        $column = Column::create();
        $column->ParentID = $row->ID;
        $column->ParentClass = $row::class;

        // getGridSettings falls back to initial when dbObject returns null
        $settings = $column->getGridSettings();
        self::assertSame(12, $settings->default->width);
    }

    // ── Container behavior ──────────────────────────────────────

    public function testGetChildrenReturnsElements(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);
        $leaf = GridTreeFactory::contentElement($column);

        $children = $column->getChildren();
        self::assertCount(1, $children);
        self::assertSame((int) $leaf->ID, (int) $children->first()->ID);
    }

    public function testGetContainerType(): void
    {
        self::assertSame(ContainerType::Column, Column::singleton()->getContainerType());
    }

    public function testGetGridWidthSummary(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = GridSettings::initial(12)->withDefault(new ViewportConfig(6, 0, true));
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        self::assertSame('6/12', $column->getGridWidthSummary());
    }

    // ── Column classes ──────────────────────────────────────────

    public function testGetColumnClassesReturnsNonEmptyString(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $classes = $column->getColumnClasses();

        self::assertNotEmpty($classes);
    }

    public function testGetColumnClassesWithOverrides(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = GridSettings::initial(12)->withOverride('md', new ViewportConfig(6, 0, true));
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        $classes = $column->getColumnClasses();

        self::assertNotEmpty($classes);
    }

    // ── getCMSFields ────────────────────────────────────────────

    public function testGetCMSFieldsIncludesGridTab(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $fields = $column->getCMSFields();

        self::assertNotNull($fields->fieldByName('Root.Grid'));
    }

    // ── GridSettings not re-initialized on subsequent write ────

    public function testGridSettingsNotReInitializedOnSubsequentWrite(): void
    {
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $custom = new GridSettings(new ViewportConfig(8, 2, true), []);
        $column = GridTreeFactory::column($row, gridSettings: $custom);

        // Write again with a title change
        $column->Title = 'Changed';
        $column->write();

        // Reload from DB
        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }
}
