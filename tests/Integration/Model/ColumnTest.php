<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Service\GridSettingsResolver;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(Column::class)]
final class ColumnTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        // Pin the active adapter to a concrete instance of the default preset
        // (Tailwind) so column-class output is deterministic regardless of the
        // container's configured SS_GRID_ADAPTER.
        Injector::inst()->registerService(new TailwindAdapter(), GridAdapterInterface::class);
    }

    public function testGridSettingsInitializedOnFirstWrite(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        // Create column without explicit GridSettings — onBeforeWrite should initialize
        ['column' => $column] = GridTreeFactory::containerTree($page);

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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $custom = (new GridSettings(new ViewportConfig(8, 2, true), []))
            ->withOverride('md', new ViewportConfig(6, 2, false));
        $column = GridTreeFactory::column($row, gridSettings: $custom);

        // Write again with a title change
        $column->Title = 'Updated';
        $column->write();

        // Reload from DB: neither the custom default nor the override may be
        // re-initialized away by the second write.
        $reloaded = Column::get()->byID($column->ID);
        self::assertInstanceOf(Column::class, $reloaded);

        $settings = $reloaded->getGridSettings();
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
        self::assertSame(6, $settings->overrides['md']->width);
        self::assertSame(2, $settings->overrides['md']->offset);
        self::assertFalse($settings->overrides['md']->visible);
    }

    public function testGetGridSettingsRoundTrip(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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

    public function testGetGridSettingsReturnsInitialWhenNoData(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
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

    public function testGetChildrenReturnsElements(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column, 'content' => $leaf] = GridTreeFactory::treeFor($page);

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
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = GridSettings::initial(12)->withDefault(new ViewportConfig(6, 0, true));
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        self::assertSame('6/12', $column->getGridWidthSummary());
    }

    public function testGetColumnClassesReturnsNonEmptyString(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $classes = $column->getColumnClasses();

        self::assertNotEmpty($classes);
    }

    /**
     * The row wrapper declares its grid unconditionally, so a column whose only
     * width class is breakpoint-prefixed gets no width below that breakpoint and
     * collapses to a single grid track. The smallest viewport must always
     * contribute an unprefixed width class.
     *
     * The trailing `lg:col-span-12` is the isolated strategy reasserting the
     * default above the single `md` override, not part of the invariant.
     */
    public function testGetColumnClassesAlwaysEmitsAnUnprefixedWidthForTheSmallestViewport(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = GridSettings::initial(12)->withOverride('md', new ViewportConfig(6, 0, true));
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        self::assertSame('col-span-12 md:col-span-6 lg:col-span-12', $column->getColumnClasses());
    }

    /**
     * getColumnClasses() must resolve grid classes through the DI-configured
     * GridSettingsResolver, which the Column receives via $dependencies injection
     * at construction. With `cascade` configured, an override at a larger viewport
     * cascades down to smaller viewports — producing different classes than the
     * default `isolated` strategy, where the override applies to its own viewport
     * only.
     */
    public function testGetColumnClassesHonoursConfiguredCascadeStrategy(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        // Default width 12, override only at 'lg' (width 6). Under isolated, the
        // smaller viewports keep the default 12, so Tailwind's base viewport renders
        // the unprefixed col-span-12 and the override surfaces as lg:col-span-6.
        // Under cascade, the 'lg' override flows down to the smallest viewport, so
        // the base width becomes 6 (col-span-6) and the standalone 12 class
        // disappears.
        $settings = GridSettings::initial(12)->withOverride('lg', new ViewportConfig(6, 0, true));

        // Baseline: column built under the default (isolated) resolver.
        $isolatedColumn = GridTreeFactory::column($row, gridSettings: $settings);
        $isolatedClasses = $isolatedColumn->getColumnClasses();
        self::assertSame('col-span-12 lg:col-span-6 xl:col-span-12', $isolatedClasses);

        // Register a cascade-configured resolver, then build a column so it receives
        // that strategy via $dependencies injection at construction.
        $adapter = Injector::inst()->get(GridAdapterInterface::class);
        Injector::inst()->registerService(new GridSettingsResolver($adapter, 'cascade'), GridSettingsResolver::class);

        $cascadeColumn = GridTreeFactory::column($row, gridSettings: $settings);
        $cascadeClasses = $cascadeColumn->getColumnClasses();

        self::assertNotSame(
            $isolatedClasses,
            $cascadeClasses,
            'getColumnClasses() must reflect the DI-configured cascade strategy, not a hard-coded isolated resolver',
        );

        // Cascade pushes the width-6 override down to the smallest viewport, so the
        // unprefixed class carries 6 and only xl reverts to the default 12.
        self::assertSame('col-span-6 xl:col-span-12', $cascadeClasses);
    }

    public function testGetCMSFieldsIncludesGridTab(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $fields = $column->getCMSFields();

        self::assertNotNull($fields->fieldByName('Root.Grid'));
    }
}
