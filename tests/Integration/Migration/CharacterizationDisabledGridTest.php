<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;

/**
 * Golden-master (characterization) test for the disabled-grid reconciliation
 * pass that runs AFTER the per-page migration loop. It pins that a grid-disabled
 * page receives no migrated content yet still has its `UseGrid` toggle carried
 * forward to 0 on every stage where the legacy flag was off, while a grid-enabled
 * sibling migrates normally — all in ONE multi-page run.
 *
 * It pins the CURRENT observed output of the migration pipeline; it does not
 * assert intended behaviour. If it fails, the expected value is mis-pinned —
 * correct the expectation, never touch `src/`.
 *
 * Perturbation thought-experiment (why this snapshot is a real gate, not a
 * smoke test). The assertions would FAIL if any of these risk surfaces
 * regressed:
 *  (a) {@see GridMigrationService::migrateDisabledGridPages} were hoisted into,
 *      or skipped after, the per-page loop: page 2's draft/live `UseGrid` would
 *      stop being reset to 0 and the flag assertions would fail.
 *  (b) The live-disabled branch stopped passing `includeLive: true`: page 2's
 *      `Page_Live.UseGrid` would keep its pre-migration value and the
 *      `useGridFlag($p2, LIVE) === 0` assertion would fail.
 *  (c) Eligibility leaked a grid-disabled page into content migration: page 2
 *      would gain a Section and `snapshotTree(...) === []` would fail.
 *
 * Note: WS5 #8's intentional change updates the FLUENT golden master (Task 4);
 * this non-Fluent disabled-grid behaviour must stay byte-identical.
 */
#[CoversNothing]
final class CharacterizationDisabledGridTest extends CharacterizationTestCase
{
    private const int AREA_ID_1 = 100;

    private const int AREA_ID_2 = 200;

    public function testDisabledGridPageReconcilesWhileEnabledSiblingMigrates(): void
    {
        $pageId1 = $this->pageId('test_page');
        $pageId2 = $this->pageId('test_page_2');

        // Page 1: grid-enabled with content.
        $this->seeder->seedPage($pageId1, self::AREA_ID_1);
        $this->seeder->seedElement($pageId1 + 1000, self::AREA_ID_1, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'P1 Body',
        ]);
        $this->seeder->seedContentMedia($pageId1 + 1000, ['HTML' => '<p>p1</p>']);

        // Page 2: grid-DISABLED, no content. Also exercise the live-disabled path
        // by publishing the page and flagging the legacy grid off on _Live.
        $this->seeder->seedPage($pageId2, self::AREA_ID_2, useGrid: false);
        $page2 = $this->objFromFixture(Page::class, 'test_page_2');
        $page2->publishSingle();
        DB::prepared_query('UPDATE "Page_Live" SET "UseElementalGrid" = 0 WHERE "ID" = ?', [$pageId2]);

        // Multi-page run with reconcileDisabledPages at its default true, so the
        // disabled-pages pass after the per-page loop is covered.
        $failures = $this->createService()->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$pageId1, $pageId2],
            reconcileDisabledPages: true,
        );
        self::assertSame(0, $failures, 'Both pages process without failures');

        // ── Page 2: grid-disabled — no content on EITHER stage ───────
        self::assertSame([], MigrationTreeSnapshot::snapshotTree($pageId2, Page::class, self::ZONE, Versioned::DRAFT), 'Disabled page has no DRAFT tree');
        self::assertSame([], MigrationTreeSnapshot::snapshotTree($pageId2, Page::class, self::ZONE, Versioned::LIVE), 'Disabled page has no LIVE tree');
        self::assertFalse(
            MigrationTreeSnapshot::recordExistsOnStage('WeDevelop_Grid_Section', $pageId2, self::ZONE, Versioned::DRAFT),
            'No Section is created on DRAFT for a grid-disabled page',
        );
        self::assertFalse(
            MigrationTreeSnapshot::recordExistsOnStage('WeDevelop_Grid_Section', $pageId2, self::ZONE, Versioned::LIVE),
            'No Section is created on LIVE for a grid-disabled page',
        );

        // ── Page 2: UseGrid toggle carried forward to 0 on both stages ─
        self::assertSame(0, MigrationTreeSnapshot::useGridFlag($pageId2, Versioned::DRAFT), 'Disabled page draft UseGrid is 0');
        self::assertSame(0, MigrationTreeSnapshot::useGridFlag($pageId2, Versioned::LIVE), 'Live-disabled page live UseGrid is 0');

        // ── Page 1: grid-enabled — migrated normally ─────────────────
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId1, Versioned::DRAFT), 'Enabled sibling draft UseGrid is 1');
        self::assertTrue(
            MigrationTreeSnapshot::recordExistsOnStage('WeDevelop_Grid_Section', $pageId1, self::ZONE, Versioned::DRAFT),
            'The enabled sibling migrated a Section on DRAFT',
        );
    }
}
