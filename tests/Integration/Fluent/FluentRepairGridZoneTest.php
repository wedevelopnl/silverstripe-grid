<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Fluent;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\ORM\DB;
use Symfony\Component\Console\Command\Command;
use TractorCow\Fluent\Service\CopyToLocaleService;
use WeDevelop\Grid\Task\OneTime\Beta5\RepairGridZoneTask;
use WeDevelop\Grid\Task\OneTime\Beta5\StageRow;
use WeDevelop\Grid\Task\OneTime\Beta5\StageTable;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\ManipulatesGridZoneTables;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * GridElement is locale-isolated, so a page carries one root sequence per
 * locale. The repair reads and writes raw rows and must reach all of them,
 * while resolving the page's zones in a locale the page actually exists in.
 */
#[CoversClass(RepairGridZoneTask::class)]
#[CoversClass(StageTable::class)]
#[CoversClass(StageRow::class)]
final class FluentRepairGridZoneTest extends FluentGridTestCase
{
    use ManipulatesGridZoneTables;

    public function testRepairsZonelessRootsInEveryLocale(): void
    {
        $page = $this->createPage();
        GridTreeFactory::section($page);
        CopyToLocaleService::singleton()->copyToLocale(Page::class, (int) $page->ID, 'en_US', 'nl_NL');

        /** @var list<int> $rootIds */
        $rootIds = array_map('intval', DB::prepared_query(
            'SELECT "ID" FROM "WeDevelop_Grid_GridElement" WHERE "ParentClass" = ? AND "ParentID" = ? ORDER BY "ID"',
            [Page::class, $page->ID],
        )->column());
        self::assertCount(2, $rootIds, 'precondition: one root per locale');

        foreach ($rootIds as $rootId) {
            $this->setZone($rootId, null);
        }

        $result = TaskRunner::run(RepairGridZoneTask::singleton());

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        foreach ($rootIds as $rootId) {
            self::assertSame('main', $this->zoneOf($rootId));
        }
    }
}
