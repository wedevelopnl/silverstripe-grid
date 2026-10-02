<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Task\OneTime\Beta5;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Task\OneTime\Beta4\BackfillGridZoneTask;
use WeDevelop\Grid\Task\OneTime\Beta5\RepairGridZoneTask;
use WeDevelop\Grid\Task\OneTime\Beta5\StageRow;
use WeDevelop\Grid\Task\OneTime\Beta5\StageTable;
use WeDevelop\Grid\Tests\Integration\Support\CleansGridTables;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\FieldsFailingTestPage;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\ManipulatesGridZoneTables;
use WeDevelop\Grid\Tests\Integration\Support\MultiZoneTestPage;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * Rows written before the zone rule existed can break it: a page root with no
 * zone renders nowhere, a nested element with one is skipped by every root
 * read, and either blocks the page's publish once the rule is in force. Each
 * test builds a valid tree through the ORM and then corrupts it with raw SQL,
 * the only way left to reach those states.
 */
#[CoversClass(RepairGridZoneTask::class)]
#[CoversClass(StageTable::class)]
#[CoversClass(StageRow::class)]
#[CoversClass(BackfillGridZoneTask::class)]
final class RepairGridZoneTaskTest extends SapphireTest
{
    use CleansGridTables;
    use DisablesAutoScaffolding;
    use ManipulatesGridZoneTables;

    private const string OBSOLETE_SECTION_TABLE = '_obsolete_WeDevelop_Grid_Section';

    private const string REMOVED_CLASS = 'App\\Removed\\Container';

    /** The ParentClass column definition to restore, once a test has widened it. */
    private ?string $originalParentClassType = null;

    protected static $fixture_file = __DIR__ . '/../../../Fixture/page.yml';

    /** @var array<class-string> */
    protected static $extra_dataobjects = [
        MultiZoneTestPage::class,
        FieldsFailingTestPage::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        // The DDL some tests need commits MySQL's per-test transaction, so
        // their rows outlive the rollback. Purged here, before every test.
        $this->cleanGridTables();
    }

    protected function tearDown(): void
    {
        $this->dropSeededTables();
        $this->restoreParentClassColumn();

        parent::tearDown();
    }

    /**
     * ParentClass is an ENUM. dev/build keeps a class name that rows still use
     * in it after the class is deleted, so such a value is a real state — but
     * one a test can only reach by widening the column itself.
     */
    private function allowRemovedParentClass(): void
    {
        $type = (string) DB::query(
            'SELECT "COLUMN_TYPE" FROM "information_schema"."COLUMNS" WHERE "TABLE_SCHEMA" = DATABASE() '
            . 'AND "TABLE_NAME" = \'WeDevelop_Grid_GridElement\' AND "COLUMN_NAME" = \'ParentClass\'',
        )->value();
        $this->originalParentClassType = $type;

        DB::query(sprintf(
            'ALTER TABLE "WeDevelop_Grid_GridElement" MODIFY "ParentClass" %s',
            substr($type, 0, -1) . ",'" . addslashes(self::REMOVED_CLASS) . "')",
        ));
    }

    private function restoreParentClassColumn(): void
    {
        if ($this->originalParentClassType === null) {
            return;
        }

        DB::prepared_query(
            'UPDATE "WeDevelop_Grid_GridElement" SET "ParentClass" = NULL WHERE "ParentClass" = ?',
            [self::REMOVED_CLASS],
        );
        DB::query(sprintf('ALTER TABLE "WeDevelop_Grid_GridElement" MODIFY "ParentClass" %s', $this->originalParentClassType));
        $this->originalParentClassType = null;
    }

    /** @return array{exitCode: int, output: string} */
    private function runTask(bool $dryRun = false): array
    {
        return TaskRunner::run(RepairGridZoneTask::singleton(), $dryRun ? ['--dry-run' => true] : []);
    }

    private function page(): Page
    {
        return $this->objFromFixture(Page::class, 'test_page');
    }

    private function multiZonePage(): MultiZoneTestPage
    {
        $page = MultiZoneTestPage::create();
        $page->Title = 'Two zones';
        $page->write();

        return $page;
    }

    /** @param array<int, string> $stages */
    private function corruptZone(GridElement $element, ?string $zone, array $stages = ['']): void
    {
        foreach ($stages as $suffix) {
            $this->setZone((int) $element->ID, $zone, $suffix);
        }
    }

    /** @return list<int> IDs of the page's roots in $zone, in render order. */
    private function rootIdsIn(DataObject $page, string $zone): array
    {
        $ids = [];
        foreach ($page->GridZone($zone) as $root) {
            $ids[] = (int) $root->ID;
        }

        return $ids;
    }

    /** @return list<array<string, mixed>> Every row of the three stage tables. */
    private function snapshot(): array
    {
        $rows = [];
        foreach (['', '_Live', '_Versions'] as $suffix) {
            foreach (DB::query(sprintf('SELECT * FROM "%s%s" ORDER BY "ID"', self::GRID_ELEMENT_TABLE, $suffix)) as $row) {
                $rows[] = ['table' => $suffix, ...$row];
            }
        }

        return $rows;
    }

    private static function setLine(GridElement $element, string $stage, DataObject $page, bool $dryRun = false): string
    {
        return sprintf(
            '%s zone "main" on %s #%d (%s), page %s #%d',
            $dryRun ? 'Would set' : 'Set',
            $element::class,
            $element->ID,
            $stage,
            $page::class,
            $page->ID,
        );
    }

    private static function manualLine(GridElement $element, string $stage, string $parentClass, int $parentId, string $reason): string
    {
        return sprintf('  - %s #%d (%s) on %s #%d: %s', $element::class, $element->ID, $stage, $parentClass, $parentId, $reason);
    }

    public function testAssignsThePagesOnlyZoneToZonelessRootsOfBothClasses(): void
    {
        $page = $this->page();
        $kept = GridTreeFactory::section($page);
        $section = GridTreeFactory::section($page);
        $reference = GridTreeFactory::reference($page, GridTreeFactory::sharedBlock());
        $page->publishRecursive();

        $this->corruptZone($section, null, ['', '_Live']);
        $this->corruptZone($reference, '', ['', '_Live']);
        self::assertSame([(int) $kept->ID], $this->rootIdsIn($page, 'main'), 'precondition: the corrupt roots render nowhere');

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        $expectedOrder = [(int) $kept->ID, (int) $section->ID, (int) $reference->ID];
        self::assertSame($expectedOrder, $this->rootIdsIn($page, 'main'), 'the editor reads them again');
        self::assertSame(
            $expectedOrder,
            Versioned::withVersionedMode(function () use ($page): array {
                Versioned::set_stage(Versioned::LIVE);

                return $this->rootIdsIn($page, 'main');
            }),
            'the published page renders them again',
        );
        self::assertStringContainsString(self::setLine($section, 'live', $page), $result['output']);
        self::assertStringContainsString('Done: 4 row(s) repaired, 0 row(s) left for manual review.', $result['output']);
    }

    public function testAppendsRepairedRootsAfterEachStagesZoneKeepingTheirRelativeOrder(): void
    {
        // Draft holds a root live has not seen yet, so each stage's zone ends
        // at a different Sort. Appending after the draft's end on live would
        // leave a gap there; history rows follow the record they belong to.
        $page = $this->page();
        $kept = GridTreeFactory::section($page, sort: 1);
        $later = GridTreeFactory::section($page, sort: 5);
        $earlier = GridTreeFactory::section($page, sort: 3);
        GridTreeFactory::section($page, zone: 'sidebar', sort: 9);
        $page->publishRecursive();
        $draftOnly = GridTreeFactory::section($page, sort: 6);
        $later->Title = 'Second version';
        $later->write();

        $this->corruptZone($later, null, ['', '_Live', '_Versions']);
        $this->corruptZone($earlier, null, ['', '_Live', '_Versions']);

        $this->runTask();

        self::assertSame(
            [1, 6, 7, 8],
            array_map($this->sortOf(...), [(int) $kept->ID, (int) $draftOnly->ID, (int) $earlier->ID, (int) $later->ID]),
            'draft',
        );
        self::assertSame(
            [1, 2, 3],
            array_map(fn (int $id): int => $this->sortOf($id, '_Live'), [(int) $kept->ID, (int) $earlier->ID, (int) $later->ID]),
            'live',
        );
        foreach ([7 => $earlier, 8 => $later] as $sort => $section) {
            $versionSorts = $this->versionSortsOf((int) $section->ID);
            self::assertSame(array_fill(0, count($versionSorts), $sort), $versionSorts, 'every history row of #' . $section->ID);
        }
    }

    public function testClearsTheZoneOfEveryElementNotDirectlyOnAPage(): void
    {
        $page = $this->page();
        $tree = GridTreeFactory::treeFor($page);
        $blockRoot = GridTreeFactory::section(GridTreeFactory::sharedBlock());

        foreach ([$tree['row'], $tree['column'], $tree['content'], $blockRoot] as $element) {
            $this->corruptZone($element, 'main');
        }

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        foreach ([$tree['row'], $tree['column'], $tree['content'], $blockRoot] as $element) {
            self::assertSame('', $this->zoneOf((int) $element->ID), $element::class . ' loses its zone');
        }
        self::assertSame('main', $this->zoneOf((int) $tree['section']->ID), 'the root keeps its zone');
        self::assertStringContainsString(
            sprintf('Cleared zone "main" on %s #%d (draft), parent %s #%d', $blockRoot::class, $blockRoot->ID, SharedBlock::class, $blockRoot->ParentID),
            $result['output'],
        );
    }

    public function testLeavesARootOnAPageWithSeveralZonesForManualReview(): void
    {
        $page = $this->multiZonePage();
        $section = GridTreeFactory::section($page);
        $this->corruptZone($section, null);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame('', $this->zoneOf((int) $section->ID), 'left untouched');
        self::assertStringContainsString(
            self::manualLine($section, 'draft', MultiZoneTestPage::class, (int) $page->ID, 'the page has several grid areas (main, sidebar)'),
            $result['output'],
        );
        self::assertStringContainsString('Done: 0 row(s) repaired, 1 row(s) left for manual review.', $result['output']);
    }

    public function testLeavesARootOnAPageWithTheGridSwitchedOffForManualReview(): void
    {
        Page::config()->set('enable_editor_toggle', true);

        $page = $this->page();
        $section = GridTreeFactory::section($page);
        $page->UseGrid = false;
        $page->write();
        $this->corruptZone($section, null);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString(
            self::manualLine($section, 'draft', Page::class, (int) $page->ID, 'the page has its grid switched off (UseGrid = 0)'),
            $result['output'],
        );
    }

    public function testLeavesARootWhosePageIsGoneForManualReview(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page);
        $this->corruptZone($section, null);
        DB::prepared_query('UPDATE "WeDevelop_Grid_GridElement" SET "ParentID" = ? WHERE "ID" = ?', [987654, $section->ID]);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertStringContainsString(
            self::manualLine($section, 'draft', Page::class, 987654, 'the page does not exist'),
            $result['output'],
        );
    }

    public function testLeavesAZonedRowWhoseParentClassNoLongerExistsForManualReview(): void
    {
        $tree = GridTreeFactory::treeFor($this->page());
        $row = $tree['row'];
        $this->corruptZone($row, 'main');
        $this->allowRemovedParentClass();
        DB::prepared_query('UPDATE "WeDevelop_Grid_GridElement" SET "ParentClass" = ? WHERE "ID" = ?', [self::REMOVED_CLASS, $row->ID]);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame('main', $this->zoneOf((int) $row->ID), 'left untouched');
        self::assertStringContainsString(
            self::manualLine($row, 'draft', self::REMOVED_CLASS, (int) $tree['section']->ID, 'the parent class no longer exists'),
            $result['output'],
        );
    }

    public function testReportsAPageWhoseFieldsCannotBeBuiltAndCarriesOn(): void
    {
        $broken = FieldsFailingTestPage::create();
        $broken->Title = 'Broken';
        $broken->write();
        $stranded = GridTreeFactory::section($broken);
        $this->corruptZone($stranded, null);

        $page = $this->page();
        $repairable = GridTreeFactory::section($page);
        $this->corruptZone($repairable, null);

        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame('main', $this->zoneOf((int) $repairable->ID), 'the other page is still repaired');
        self::assertStringContainsString(
            self::manualLine(
                $stranded,
                'draft',
                FieldsFailingTestPage::class,
                (int) $broken->ID,
                'its grid areas could not be read: ' . FieldsFailingTestPage::FAILURE,
            ),
            $result['output'],
        );
    }

    public function testRepairsEachStageByThatStagesOwnParent(): void
    {
        // Live still holds the section on the single-zone page; draft has
        // since moved it to a page with two. One row is repairable, the other
        // is not — deciding both by the draft parent would get live wrong.
        $page = $this->page();
        $section = GridTreeFactory::section($page);
        $page->publishRecursive();

        $multi = $this->multiZonePage();
        $section->ParentID = $multi->ID;
        $section->ParentClass = MultiZoneTestPage::class;
        $section->write();

        $this->corruptZone($section, null, ['', '_Live']);

        $result = $this->runTask();

        self::assertSame('main', $this->zoneOf((int) $section->ID, '_Live'));
        self::assertSame('', $this->zoneOf((int) $section->ID), 'draft is left for review');
        self::assertSame(Command::FAILURE, $result['exitCode']);
    }

    public function testRepairsLiveInPlaceWithoutPublishingPendingDraftEdits(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page, title: 'Published title');
        $page->publishRecursive();

        $section->Title = 'Unpublished edit';
        $section->write();
        $this->corruptZone($section, null, ['_Live']);

        $this->runTask();

        self::assertSame('main', $this->zoneOf((int) $section->ID, '_Live'));
        $liveTitle = DB::prepared_query('SELECT "Title" FROM "WeDevelop_Grid_GridElement_Live" WHERE "ID" = ?', [$section->ID])->value();
        self::assertSame('Published title', $liveTitle);
    }

    public function testRepairsEveryHistoryRow(): void
    {
        $page = $this->page();
        $section = GridTreeFactory::section($page);
        $section->Title = 'Second version';
        $section->write();
        $this->corruptZone($section, null, ['', '_Versions']);

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        $versions = $this->versionRowsFor((int) $section->ID);
        self::assertCount(2, $versions);
        foreach ($versions as $row) {
            self::assertSame('main', $this->versionZoneOf((int) $section->ID, $row['Version']));
        }
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $page = $this->page();
        $tree = GridTreeFactory::treeFor($page);
        $page->publishRecursive();
        $this->corruptZone($tree['section'], null, ['', '_Live', '_Versions']);
        $this->corruptZone($tree['row'], 'main', ['', '_Live', '_Versions']);
        $multiRoot = GridTreeFactory::section($this->multiZonePage());
        $this->corruptZone($multiRoot, null);

        $before = $this->snapshot();
        $result = $this->runTask(dryRun: true);

        self::assertSame($before, $this->snapshot(), 'a dry run changes nothing');
        self::assertSame(Command::FAILURE, $result['exitCode'], 'same exit rule as a real run');
        self::assertStringContainsString(self::setLine($tree['section'], 'draft', $page, dryRun: true), $result['output']);
        self::assertStringContainsString(
            self::manualLine($multiRoot, 'draft', MultiZoneTestPage::class, (int) $multiRoot->ParentID, 'the page has several grid areas (main, sidebar)'),
            $result['output'],
        );
        self::assertMatchesRegularExpression('/Dry run: \d+ row\(s\) would be repaired, 1 row\(s\) left for manual review\./', $result['output']);
    }

    public function testIsIdempotent(): void
    {
        $page = $this->page();
        $tree = GridTreeFactory::treeFor($page);
        $page->publishRecursive();
        $this->corruptZone($tree['section'], null, ['', '_Live', '_Versions']);
        $this->corruptZone($tree['content'], 'main', ['', '_Live']);

        $this->runTask();
        $afterFirst = $this->snapshot();
        $second = $this->runTask();

        self::assertSame($afterFirst, $this->snapshot());
        self::assertSame(Command::SUCCESS, $second['exitCode']);
        self::assertStringContainsString('Done: 0 row(s) repaired, 0 row(s) left for manual review.', $second['output']);
    }

    public function testRefusesToRunWhileBackfillStillHasZonesToCopy(): void
    {
        // Backfill only fills base rows whose Zone is still empty. Assigning
        // one first would stop it restoring the legacy zone for good.
        $page = $this->page();
        $section = GridTreeFactory::section($page);
        $this->corruptZone($section, null);
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [(int) $section->ID => 'sidebar']);

        $before = $this->snapshot();
        $result = $this->runTask();

        self::assertSame(Command::FAILURE, $result['exitCode']);
        self::assertSame($before, $this->snapshot(), 'nothing is written');
        self::assertStringContainsString(
            '1 row(s) still have a legacy zone to copy. Run "sake tasks:backfill-grid-zone" first.',
            $result['output'],
        );
    }

    public function testRunsOnceBackfillHasNothingLeftToCopy(): void
    {
        $page = $this->page();
        $copied = GridTreeFactory::section($page);
        $section = GridTreeFactory::section($page);
        $this->corruptZone($section, null);
        // A legacy table may outlive the backfill; only its uncopied rows count.
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [(int) $copied->ID => 'main']);

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame('main', $this->zoneOf((int) $section->ID));
    }

    public function testSucceedsWithNothingToRepair(): void
    {
        GridTreeFactory::treeFor($this->page());
        GridTreeFactory::reference($this->page(), GridTreeFactory::sharedBlock());

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertStringContainsString('Done: 0 row(s) repaired, 0 row(s) left for manual review.', $result['output']);
    }
}
