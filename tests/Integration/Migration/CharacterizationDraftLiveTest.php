<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;

/**
 * Golden-master (characterization) test for the most behaviour-dense migration
 * scenario: a page whose legacy content exists on BOTH the draft and live
 * stages, with draft↔live content/size divergence, a draft-only element, a
 * live-only element, and the live GridSettings reconciliation pass.
 *
 * It pins the CURRENT observed output of the migration pipeline; it does not
 * assert intended behaviour. If it fails, the expected value is mis-pinned —
 * correct the expectation, never touch `src/`.
 *
 * Perturbation thought-experiment (why this snapshot is a real gate, not a
 * smoke test). The assertions would FAIL if any of these WS5/WS6 risk surfaces
 * regressed:
 *  (a) {@see GridMigrationService::reconcileColumnLiveGridSettings} were dropped
 *      or short-circuited (a real WS6 `LivePublisher` extraction risk): the live
 *      Column would keep the draft-derived width and the LIVE-width=10 assertion
 *      (and the divergent-grouping width/overrides assertions) would fail.
 *  (b) {@see GridMigrationService::overwriteLiveContent} were skipped: the
 *      published live element would keep the draft Title/HTML and the X live
 *      Title/HTML assertions would flip back to the draft values.
 *  (c) A shared draft+live element were re-created as a live-only hierarchy
 *      instead of being published from its draft record: the shared-ID assertion
 *      would fail (the draft and live element would carry different IDs) and a
 *      spurious extra Section would appear on LIVE.
 *  (d) The live-only grouping in {@see GridMigrationService::createLiveOnlyHierarchy}
 *      stopped writing to both stages: element Z would vanish from the DRAFT tree.
 */
#[CoversNothing]
final class CharacterizationDraftLiveTest extends CharacterizationTestCase
{
    private const int AREA_ID = 100;

    /**
     * Sentinel substituted for the divergent-grouping live Column's
     * `overridesColumnRaw` before structural inspection: only the decoded
     * content is pinned, not the JSON byte layout (key order, spacing).
     */
    private const string DIVERGENT_LIVE_OVERRIDES = '<<divergent-live-overrides-json>>';

    public function testSharedDraftLivePageDivergesAndReconciles(): void
    {
        $pageId = $this->pageId();
        $this->seedSharedDraftLivePage($pageId);
        $this->publishPageToLive($pageId, self::AREA_ID);

        $this->runMigration($pageId);

        $draftTree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::DRAFT);
        $liveTree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::LIVE);

        // ── DRAFT stage ──────────────────────────────────────────────
        // Shared element X keeps its draft Title/HTML and the draft-derived
        // width (6); no per-viewport override → NULL overrides column.
        $draftX = MigrationTreeSnapshot::findColumnContaining($draftTree, 'Draft X');
        self::assertNotNull($draftX, 'Shared element X is present on DRAFT under its draft Title');
        self::assertSame(6, $draftX['gridDefault']['width'], 'Draft X column width derives from the draft Size');
        self::assertSame(0, $draftX['gridDefault']['offset']);
        self::assertTrue($draftX['gridDefault']['visible']);
        self::assertNull($draftX['overridesColumnRaw'], 'Draft X column has no per-viewport override');
        self::assertSame('<p>draft</p>', MigrationTreeSnapshot::elementHtml($draftX, 'Draft X'), 'Draft X keeps draft HTML');

        // Draft-only element Y is present on DRAFT.
        self::assertContains('Draft Y', MigrationTreeSnapshot::allElementTitles($draftTree), 'Draft-only element Y is on DRAFT');

        // OBSERVED-AND-PINNED (truth #1): live-only element Z. createLiveOnlyHierarchy
        // writes live-only content to BOTH stages (write() for draft, then
        // writeToStage(LIVE)), so Z's records DO appear on DRAFT. Pin the observed
        // presence; do not assume it is live-only.
        self::assertContains('Live Z', MigrationTreeSnapshot::allElementTitles($draftTree), 'Live-only Z is written to DRAFT too');

        // ── LIVE stage ───────────────────────────────────────────────
        // Shared element X on live: width reconciled to the live Size (10), and
        // Title/HTML overwritten with the live values (overwriteLiveContent).
        $liveX = MigrationTreeSnapshot::findColumnContaining($liveTree, 'Live X');
        self::assertNotNull($liveX, 'Shared element X is published to LIVE under its live Title');
        self::assertSame(10, $liveX['gridDefault']['width'], 'Live X column width reconciles from the live Size');
        self::assertSame('<p>live</p>', MigrationTreeSnapshot::elementHtml($liveX, 'Live X'), 'Live X carries the live HTML');

        // The draft-stage Title is NOT on live, proving the live record was
        // overwritten rather than left as the published draft copy.
        self::assertNotContains('Draft X', MigrationTreeSnapshot::allElementTitles($liveTree), 'Live X is not the draft Title');

        // Draft-only Y is absent on LIVE (never published).
        self::assertNotContains('Draft Y', MigrationTreeSnapshot::allElementTitles($liveTree), 'Draft-only Y is not on LIVE');

        // Live-only Z is present on LIVE.
        self::assertContains('Live Z', MigrationTreeSnapshot::allElementTitles($liveTree), 'Live-only Z is on LIVE');

        // Shared element X has the SAME record ID on both stages — it was
        // published from the draft record, not re-created live-only.
        self::assertSame(
            $this->elementId('Draft X', Versioned::DRAFT),
            $this->elementId('Live X', Versioned::LIVE),
            'Shared element X has one record ID across draft and live',
        );

        // UseGrid flag set on both stages (the live path ran because the page is
        // published with legacy grid content on _Live).
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId, Versioned::DRAFT));
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId, Versioned::LIVE));
    }

    /**
     * Two elements sharing the same DRAFT size group into ONE Column; their LIVE
     * sizes diverge. A single Column cannot express two widths, so the live
     * reconciliation takes the FIRST element's live settings (current behaviour
     * of reconcileGridSettings) and a divergence warning is logged.
     */
    public function testDivergentGroupingLiveColumnTakesFirstElementSettings(): void
    {
        $pageId = $this->pageId();
        $this->seedDivergentGroupingPage($pageId);

        $this->runMigration($pageId);

        // DRAFT: both elements share one column (same draft Size 6).
        $draftTree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::DRAFT);
        $draftColumn = MigrationTreeSnapshot::findColumnContaining($draftTree, 'Draft P');
        self::assertNotNull($draftColumn);
        self::assertSame(6, $draftColumn['gridDefault']['width'], 'Draft column width derives from the shared draft Size');
        $draftTitles = MigrationTreeSnapshot::columnElementTitles($draftColumn);
        self::assertSame(['Draft P', 'Draft Q'], $draftTitles, 'Both elements group into the one draft column');

        // LIVE: the column takes the FIRST element (P) live settings — width 10
        // and P's per-viewport lg override — not Q's divergent live width.
        $liveTree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::LIVE);
        $liveColumn = MigrationTreeSnapshot::findColumnContaining($liveTree, 'Live P');
        self::assertNotNull($liveColumn);
        self::assertSame(10, $liveColumn['gridDefault']['width'], 'Live column takes the first element P live width');

        // OBSERVED-AND-PINNED (truth #2): the live column's raw overrides reflect
        // P's live settings. Assert the decoded content, then normalise the
        // format-sensitive JSON to a sentinel and pin the FULL live column struct.
        MigrationTreeSnapshot::assertLgOverrideWidth($liveColumn['overridesColumnRaw'], 5);

        // Substitute the sentinel, then pin the whole live column — both shared
        // elements P and Q are published into the one column, P first, with P's
        // reconciled gridDefault. This catches element/sort/gridDefault drift too,
        // not just the override content.
        $liveColumn['overridesColumnRaw'] = self::DIVERGENT_LIVE_OVERRIDES;
        self::assertSame($this->expectedDivergentLiveColumn(), $liveColumn);

        // A divergence warning naming the column was logged (Q diverges from P).
        $warnings = $this->logMessages('warning');
        self::assertNotEmpty(
            array_filter($warnings, static fn (string $message): bool => str_contains($message, 'diverge')),
            'Divergent live grid settings log a warning',
        );
    }

    /**
     * Pinned LIVE column for the divergent-grouping case: width reconciled to the
     * first element's live Size (10), `overridesColumnRaw` normalised to the
     * sentinel, and both shared elements published in order (P then Q) with their
     * live Titles/HTML.
     *
     * @return array{
     *     sort: int,
     *     gridDefault: array{width: positive-int, offset: int<0, max>, visible: bool},
     *     overridesColumnRaw: string,
     *     elements: list<array{
     *         title: string,
     *         showTitle: bool,
     *         titleTag: string,
     *         titleClass: string,
     *         extraClass: string,
     *         sort: int,
     *         className: string,
     *         html: string|null,
     *     }>,
     * }
     */
    private function expectedDivergentLiveColumn(): array
    {
        return [
            'sort' => 1,
            'gridDefault' => ['width' => 10, 'offset' => 0, 'visible' => true],
            'overridesColumnRaw' => self::DIVERGENT_LIVE_OVERRIDES,
            'elements' => [
                [
                    'title' => 'Live P',
                    'showTitle' => false,
                    'titleTag' => 'h2',
                    'titleClass' => '',
                    'extraClass' => '',
                    'sort' => 1,
                    'className' => ContentElement::class,
                    'html' => '<p>p-live</p>',
                ],
                [
                    'title' => 'Live Q',
                    'showTitle' => false,
                    'titleTag' => 'h2',
                    'titleClass' => '',
                    'extraClass' => '',
                    'sort' => 2,
                    'className' => ContentElement::class,
                    'html' => '<p>q-live</p>',
                ],
            ],
        ];
    }

    /**
     * Seed (BOTH stages): a shared element X with diverging draft/live size and
     * content, a draft-only element Y (distinct width), and a live-only element
     * Z. No explicit Row delimiter — the strategy builds the default
     * Section → Row → Column chain.
     *
     * @param positive-int $pageId
     */
    private function seedSharedDraftLivePage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID);

        // Shared element X — width 6 on draft, 10 on live; draft/live HTML+Title diverge.
        $this->seeder->seedElement(5000, self::AREA_ID, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Draft X',
        ], 'draft');
        $this->seeder->seedContentMedia(5000, ['HTML' => '<p>draft</p>'], 'draft');
        $this->seeder->seedElement(5000, self::AREA_ID, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
            'Title' => 'Live X',
        ], 'live');
        $this->seeder->seedContentMedia(5000, ['HTML' => '<p>live</p>'], 'live');

        // Draft-only element Y — distinct width 4 so it does not group with X.
        $this->seeder->seedElement(5001, self::AREA_ID, self::CONTENT_CLASS, 2, [
            'SizeMD' => 4,
            'Title' => 'Draft Y',
        ], 'draft');
        $this->seeder->seedContentMedia(5001, ['HTML' => '<p>y</p>'], 'draft');

        // Live-only element Z — width 12, no draft counterpart.
        $this->seeder->seedElement(5002, self::AREA_ID, self::CONTENT_CLASS, 3, [
            'SizeMD' => 12,
            'Title' => 'Live Z',
        ], 'live');
        $this->seeder->seedContentMedia(5002, ['HTML' => '<p>z</p>'], 'live');
    }

    /**
     * Seed (BOTH stages): two elements P and Q sharing the same draft Size (group
     * into one Column) whose live Sizes diverge — P gains a per-viewport lg
     * override; Q's live width differs.
     *
     * @param positive-int $pageId
     */
    private function seedDivergentGroupingPage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID);

        // Draft: both SizeMD 6 → group into one column.
        $this->seeder->seedElement(6300, self::AREA_ID, self::CONTENT_CLASS, 1, [
            'SizeMD' => 6,
            'Title' => 'Draft P',
        ], 'draft');
        $this->seeder->seedContentMedia(6300, ['HTML' => '<p>p</p>'], 'draft');
        $this->seeder->seedElement(6301, self::AREA_ID, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'Draft Q',
        ], 'draft');
        $this->seeder->seedContentMedia(6301, ['HTML' => '<p>q</p>'], 'draft');

        // Live: P → width 10 with lg override 5; Q → width 4. The column takes P.
        $this->seeder->seedElement(6300, self::AREA_ID, self::CONTENT_CLASS, 1, [
            'SizeMD' => 10,
            'SizeLG' => 5,
            'Title' => 'Live P',
        ], 'live');
        $this->seeder->seedContentMedia(6300, ['HTML' => '<p>p-live</p>'], 'live');
        $this->seeder->seedElement(6301, self::AREA_ID, self::CONTENT_CLASS, 2, [
            'SizeMD' => 4,
            'Title' => 'Live Q',
        ], 'live');
        $this->seeder->seedContentMedia(6301, ['HTML' => '<p>q-live</p>'], 'live');
    }

    /**
     * Resolve a migrated content element's record ID by Title on a stage, read
     * directly from the stage-appropriate base GridElement table (the snapshot
     * helper intentionally does not expose IDs).
     *
     * @param non-empty-string $title
     * @param non-empty-string $stage
     *
     * @return positive-int
     */
    private function elementId(string $title, string $stage): int
    {
        $table = $stage === Versioned::LIVE
            ? 'WeDevelop_Grid_GridElement_Live'
            : 'WeDevelop_Grid_GridElement';

        $value = DB::prepared_query(
            \sprintf('SELECT "ID" FROM "%s" WHERE "Title" = ?', $table),
            [$title],
        )->value();

        $id = (int) $value;
        \assert($id > 0);

        return $id;
    }
}
