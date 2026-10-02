<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Task\OneTime\Beta4;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use WeDevelop\Grid\Task\OneTime\Beta4\BackfillGridZoneTask;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\ManipulatesGridZoneTables;
use WeDevelop\Grid\Tests\Integration\Support\TaskRunner;

/**
 * The Zone hoist moves a $db field from the root-element subclasses to the
 * GridElement base. dev/build creates the new base column EMPTY and leaves the
 * old values behind, so without this task every page's zone assignment reads as
 * '' and the whole grid disappears from its zones.
 *
 * A test database is built from current code and so has no legacy column at all;
 * each test manufactures the pre-upgrade state it needs. That is also the only
 * way to reach both shapes dev/build leaves behind: Section was left with no own
 * fields so its whole table was RENAMED to `_obsolete_…`, while
 * SharedBlockReference still declares BlockID so its table survived under its
 * own name with Zone orphaned on it.
 */
#[CoversClass(BackfillGridZoneTask::class)]
final class BackfillGridZoneTaskTest extends SapphireTest
{
    use DisablesAutoScaffolding;
    use ManipulatesGridZoneTables;

    private const string OBSOLETE_SECTION_TABLE = '_obsolete_WeDevelop_Grid_Section';

    private const string REFERENCE_TABLE = 'WeDevelop_Grid_SharedBlockReference';

    protected static $fixture_file = __DIR__ . '/../../../Fixture/page.yml';

    private bool $addedReferenceZoneColumn = false;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
    }

    protected function tearDown(): void
    {
        $this->dropSeededTables();

        if ($this->addedReferenceZoneColumn) {
            DB::query(sprintf('ALTER TABLE "%s" DROP COLUMN "Zone"', self::REFERENCE_TABLE));
            $this->addedReferenceZoneColumn = false;
        }

        parent::tearDown();
    }

    /**
     * The other shape: the table survives, Zone is orphaned on it.
     *
     * @param array<int, string> $zonesById
     */
    private function orphanZoneColumnOnReferenceTable(array $zonesById): void
    {
        DB::query(sprintf('ALTER TABLE "%s" ADD COLUMN "Zone" VARCHAR(50)', self::REFERENCE_TABLE));
        $this->addedReferenceZoneColumn = true;

        foreach ($zonesById as $id => $zone) {
            DB::prepared_query(
                sprintf('UPDATE "%s" SET "Zone" = ? WHERE "ID" = ?', self::REFERENCE_TABLE),
                [$zone, $id],
            );
        }
    }

    /**
     * Surrogate IDs are assigned from a deliberately offset base so they cannot
     * coincide with the base _Versions rows'. Without that, a wrong `s.ID = t.ID`
     * join lines up by accident on a small test database and the join key this
     * fixture exists to pin goes unverified.
     *
     * @param list<array{RecordID: int, Version: int, Zone: string}> $rows
     */
    private function seedObsoleteVersionsTable(string $table, array $rows): void
    {
        DB::query(sprintf('DROP TABLE IF EXISTS "%s"', $table));
        DB::query(sprintf(
            'CREATE TABLE "%s" ("ID" INT NOT NULL, "RecordID" INT NOT NULL, '
            . '"Version" INT NOT NULL, "Zone" VARCHAR(50), PRIMARY KEY ("ID"))',
            $table,
        ));
        $this->droppableTables[] = $table;

        $surrogateId = 900000;
        foreach ($rows as $row) {
            DB::prepared_query(
                sprintf('INSERT INTO "%s" ("ID", "RecordID", "Version", "Zone") VALUES (?, ?, ?, ?)', $table),
                [$surrogateId, $row['RecordID'], $row['Version'], $row['Zone']],
            );
            $surrogateId++;
        }
    }

    /** @return array{exitCode: int, output: string} */
    private function runTask(bool $dryRun = false): array
    {
        return TaskRunner::run(BackfillGridZoneTask::singleton(), $dryRun ? ['--dry-run' => true] : []);
    }

    public function testCopiesZoneFromTheObsoleteRenamedSectionTable(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $sectionId = (int) $section->ID;

        $this->setZone($sectionId, '');
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [$sectionId => 'main']);

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame('main', $this->zoneOf($sectionId));
    }

    public function testCopiesZoneFromASurvivingTableWithAnOrphanedColumn(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $block = GridTreeFactory::sharedBlock();
        GridTreeFactory::section($block, zone: '');
        $reference = GridTreeFactory::reference($page, $block, zone: 'sidebar');
        $referenceId = (int) $reference->ID;

        $this->setZone($referenceId, '');
        $this->orphanZoneColumnOnReferenceTable([$referenceId => 'sidebar']);

        $this->runTask();

        self::assertSame('sidebar', $this->zoneOf($referenceId));
    }

    public function testCopiesZonePerVersionRowNotPerRecord(): void
    {
        // _Versions rows carry their own surrogate ID and are identified by
        // (RecordID, Version). Two versions of ONE record in DIFFERENT zones is
        // what separates a correct join from an ID join: the latter pairs a
        // version row with whichever legacy row happens to share its surrogate
        // ID, so the zones land on the wrong versions or not at all.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $sectionId = (int) $section->ID;

        $section->Zone = 'sidebar';
        $section->write();

        $versions = $this->versionRowsFor($sectionId);
        self::assertCount(2, $versions, 'precondition: the section has two versions');

        $this->setZone($sectionId, '', '_Versions');

        $this->seedObsoleteVersionsTable(self::OBSOLETE_SECTION_TABLE . '_Versions', [
            ['RecordID' => $sectionId, 'Version' => $versions[0]['Version'], 'Zone' => 'main'],
            ['RecordID' => $sectionId, 'Version' => $versions[1]['Version'], 'Zone' => 'sidebar'],
        ]);

        $this->runTask();

        self::assertSame('main', $this->versionZoneOf($sectionId, $versions[0]['Version']));
        self::assertSame('sidebar', $this->versionZoneOf($sectionId, $versions[1]['Version']));
    }

    public function testDoesNotOverwriteAZoneAlreadySetOnTheBase(): void
    {
        // Re-running after an operator has re-zoned a page by hand must not
        // resurrect the stale legacy value.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'sidebar');
        $sectionId = (int) $section->ID;

        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [$sectionId => 'main']);

        $this->runTask();

        self::assertSame('sidebar', $this->zoneOf($sectionId));
    }

    public function testLeavesTheLegacyZoneOffAnElementNoLongerOnAPage(): void
    {
        // Converted to a shared block's root after the hoist: its empty base
        // zone is correct now, and copying the legacy one would break the
        // rule GridElement::validate() enforces.
        $blockRoot = GridTreeFactory::section(GridTreeFactory::sharedBlock());
        $blockRootId = (int) $blockRoot->ID;
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [$blockRootId => 'main']);

        $dryRun = $this->runTask(dryRun: true);
        $result = $this->runTask();

        self::assertStringContainsString('Dry run: 0 row(s) would be updated.', $dryRun['output']);
        self::assertStringContainsString('Done: 0 row(s) updated.', $result['output']);
        self::assertSame('', $this->zoneOf($blockRootId));
        self::assertSame(0, BackfillGridZoneTask::singleton()->pendingCopyCount());
    }

    public function testIsIdempotent(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $sectionId = (int) $section->ID;

        $this->setZone($sectionId, '');
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [$sectionId => 'main']);

        $this->runTask();
        $secondRun = $this->runTask();

        self::assertSame('main', $this->zoneOf($sectionId));
        self::assertStringContainsString('0 row(s) updated', $secondRun['output']);
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');
        $sectionId = (int) $section->ID;

        $this->setZone($sectionId, '');
        $this->seedObsoleteTable(self::OBSOLETE_SECTION_TABLE, [$sectionId => 'main']);

        $result = $this->runTask(dryRun: true);

        self::assertSame('', $this->zoneOf($sectionId), 'a dry run must not write');
        self::assertStringContainsString('1 row(s) would be updated', $result['output']);
    }

    public function testSucceedsWhenNoLegacySourceExists(): void
    {
        // The ordinary case on a fresh install, and on a second upgrade once an
        // operator has dropped the obsolete tables: nothing to copy, not an error.
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page, zone: 'main');

        $result = $this->runTask();

        self::assertSame(Command::SUCCESS, $result['exitCode']);
        self::assertSame('main', $this->zoneOf((int) $section->ID));
        self::assertStringContainsString('0 row(s) updated', $result['output']);
    }
}
