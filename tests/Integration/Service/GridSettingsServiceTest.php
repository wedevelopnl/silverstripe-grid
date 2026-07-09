<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridSettingsService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridSettingsService::class)]
final class GridSettingsServiceTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private GridSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        // Pin the active adapter to the default preset (Tailwind) so the
        // default-viewport key the service treats as the base config is
        // deterministic ('sm'), independent of the container's SS_GRID_ADAPTER.
        // Registered before the service is resolved so its adapter dependency
        // is wired to this instance.
        Injector::inst()->registerService(new TailwindAdapter(), GridAdapterInterface::class);

        $this->service = Injector::inst()->get(GridSettingsService::class);
    }

    public function testUpdateSettingsDefaultViewportUpdatesDefaultConfig(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        // Tailwind's default viewport is 'sm'
        $result = $this->service->updateSettings($column, 'sm', 6, 2, true);

        self::assertTrue($result->isOk());

        $updated = $result->unwrap();
        self::assertInstanceOf(Column::class, $updated);

        $settings = $updated->getGridSettings();
        self::assertSame(6, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    public function testUpdateSettingsNonDefaultViewportAddsOverride(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $result = $this->service->updateSettings($column, 'lg', 4, 0, false);

        self::assertTrue($result->isOk());

        $settings = $result->unwrap()->getGridSettings();
        self::assertTrue($settings->hasOverride('lg'));

        $override = $settings->getOverride('lg');
        self::assertNotNull($override);
        self::assertSame(4, $override->width);
        self::assertSame(0, $override->offset);
        self::assertFalse($override->visible);
    }

    public function testUpdateSettingsNonDefaultViewportMatchingDefaultRemovesOverride(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        self::assertTrue($column->getGridSettings()->hasOverride('lg'));

        // Set 'lg' to values matching the default — override should be removed
        $result = $this->service->updateSettings($column, 'lg', 12, 0, true);

        self::assertTrue($result->isOk());

        $updatedSettings = $result->unwrap()->getGridSettings();
        self::assertFalse($updatedSettings->hasOverride('lg'));
    }

    public function testUpdateSettingsPersistsColumnToDatabase(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        $this->service->updateSettings($column, 'sm', 8, 1, true);

        /** @var Column $reloaded */
        $reloaded = GridElement::get()->byID($column->ID);
        $settings = $reloaded->getGridSettings();

        self::assertSame(8, $settings->default->width);
        self::assertSame(1, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    public function testResetOverridesRemovesSpecificViewportOverride(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
            [
                'lg' => new ViewportConfig(6, 0, true),
                'xl' => new ViewportConfig(4, 0, true),
            ],
        );
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        $result = $this->service->resetOverrides($page, 'main', 'lg');

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap());

        /** @var Column $reloaded */
        $reloaded = GridElement::get()->byID($column->ID);
        $reloadedSettings = $reloaded->getGridSettings();

        self::assertFalse($reloadedSettings->hasOverride('lg'));
        self::assertTrue($reloadedSettings->hasOverride('xl'));
    }

    public function testResetOverridesRemovesAllOverrides(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $settings = new GridSettings(
            new ViewportConfig(12, 0, true),
            [
                'lg' => new ViewportConfig(6, 0, true),
                'xl' => new ViewportConfig(4, 0, true),
            ],
        );
        $column = GridTreeFactory::column($row, gridSettings: $settings);

        $result = $this->service->resetOverrides($page, 'main', null);

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap());

        /** @var Column $reloaded */
        $reloaded = GridElement::get()->byID($column->ID);
        self::assertSame([], $reloaded->getGridSettings()->overrides);
    }

    public function testResetOverridesReturnsZeroWhenNoColumnsHaveOverrides(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        GridTreeFactory::column($row);

        $result = $this->service->resetOverrides($page, 'main', null);

        self::assertTrue($result->isOk());
        self::assertSame(0, $result->unwrap());
    }

    public function testResetOverridesOnlyAffectsColumnsInSpecifiedZone(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');

        $mainSection = GridTreeFactory::section($page, zone: 'main');
        $mainRow = GridTreeFactory::row($mainSection);
        $mainSettings = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['lg' => new ViewportConfig(6, 0, true)],
        );
        $mainColumn = GridTreeFactory::column($mainRow, gridSettings: $mainSettings);

        $sidebarSection = GridTreeFactory::section($page, zone: 'sidebar');
        $sidebarRow = GridTreeFactory::row($sidebarSection);
        $sidebarSettings = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['lg' => new ViewportConfig(4, 0, true)],
        );
        $sidebarColumn = GridTreeFactory::column($sidebarRow, gridSettings: $sidebarSettings);

        $result = $this->service->resetOverrides($page, 'main', null);

        self::assertTrue($result->isOk());
        self::assertSame(1, $result->unwrap());

        /** @var Column $reloadedMain */
        $reloadedMain = GridElement::get()->byID($mainColumn->ID);
        self::assertSame([], $reloadedMain->getGridSettings()->overrides);

        /** @var Column $reloadedSidebar */
        $reloadedSidebar = GridElement::get()->byID($sidebarColumn->ID);
        self::assertTrue($reloadedSidebar->getGridSettings()->hasOverride('lg'));
    }

    public function testResetOverridesReturnsCountOfAffectedColumns(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $withOverrides = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['lg' => new ViewportConfig(6, 0, true)],
        );

        GridTreeFactory::column($row, gridSettings: $withOverrides);
        GridTreeFactory::column($row, gridSettings: $withOverrides);
        GridTreeFactory::column($row);

        $result = $this->service->resetOverrides($page, 'main', null);

        self::assertTrue($result->isOk());
        self::assertSame(2, $result->unwrap());
    }
}
