<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Service;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Service\GridSettingsService;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RejectMarkedColumnExtension;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Pins the transaction wrapping around {@see GridSettingsService::resetOverrides()}.
 *
 * A {@see RejectMarkedColumnExtension} fails the write of a marked column part
 * way through the reset loop. Because the whole loop runs in a single
 * transaction, the earlier (already-written) column reset must roll back so the
 * page is never left partially reset.
 */
#[CoversClass(GridSettingsService::class)]
final class GridSettingsServiceRollbackTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    /** @var array<class-string, list<class-string>> */
    protected static $required_extensions = [
        GridElement::class => [RejectMarkedColumnExtension::class],
    ];

    private GridSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        Versioned::set_stage(Versioned::DRAFT);

        $this->service = Injector::inst()->get(GridSettingsService::class);
    }

    public function testResetOverridesRollsBackEarlierColumnWhenLaterWriteFails(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);

        $withOverride = new GridSettings(
            new ViewportConfig(12, 0, true),
            ['lg' => new ViewportConfig(6, 0, true)],
        );

        // First column (lower Sort) resets cleanly; second column is marked to
        // fail the reset write after the first has already been written.
        $firstColumn = GridTreeFactory::column($row, sort: 1, gridSettings: $withOverride);
        $markedColumn = GridTreeFactory::column($row, sort: 2, gridSettings: $withOverride);

        // Inject the failure marker via raw SQL so the initial write succeeded
        // and only the reset re-validation trips the extension.
        DB::query(sprintf(
            "UPDATE \"GridElement\" SET \"ExtraClass\" = '%s' WHERE \"ID\" = %d",
            RejectMarkedColumnExtension::FAIL_MARKER,
            $markedColumn->ID,
        ));

        $result = $this->service->resetOverrides($page, 'main', null);

        self::assertTrue(
            $result->isErr(),
            'a mid-loop write failure must surface as an err Result',
        );

        // The first column's override must still be present — its reset was
        // rolled back together with the failed write.
        /** @var Column $reloadedFirst */
        $reloadedFirst = GridElement::get()->byID($firstColumn->ID);
        self::assertTrue(
            $reloadedFirst->getGridSettings()->hasOverride('lg'),
            'the earlier column reset must roll back atomically with the failed write',
        );
    }
}
