<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;

/**
 * Golden-master (characterization) test for the cross-page migration scenario:
 * TWO pages migrated in ONE service run. It pins how the pipeline keeps each
 * page's hierarchy under its OWN polymorphic parent (page IDs and element IDs
 * share the numeric namespace, so a parent-keying regression would silently
 * re-home one page's content under another), and how a Row delimiter shared
 * across draft + live publishes to exactly one Section on LIVE rather than
 * spawning a spurious live-only duplicate.
 *
 * It pins the CURRENT observed output of the migration pipeline; it does not
 * assert intended behaviour. If it fails, the expected value is mis-pinned —
 * correct the expectation, never touch `src/`.
 *
 * Perturbation thought-experiment (why this snapshot is a real gate, not a
 * smoke test). The assertions would FAIL if any of these risk surfaces
 * regressed:
 *  (a) The `$draftLegacyIds` guard in {@see GridMigrationService::publishToLive}
 *      regressed so a Row delimiter present on BOTH legacy stages were treated
 *      as live-only: page 1 would gain a spurious extra Section on LIVE and the
 *      "exactly one LIVE Section under page 1" assertion would fail.
 *  (b) The polymorphic `ParentClass:ParentID` keying broke (page/element ID
 *      namespace collision): a Section would point at the wrong page ID — the
 *      per-page parent-ref assertions would fail and titles would leak across
 *      the two page snapshots.
 *  (c) The override-collapsing logic in FieldMapper::mapGridSettings changed so
 *      page 2 Column B's `lg` override were dropped — `overridesColumnRaw` would
 *      flip from a JSON string to `null`.
 *  (d) Auto-scaffold suppression broke during the migration write — extra
 *      scaffolded Rows/Columns would appear in either page's tree.
 */
#[CoversNothing]
final class CharacterizationSharedElementsTest extends CharacterizationTestCase
{
    private const int AREA_ID_1 = 100;

    private const int AREA_ID_2 = 200;

    /**
     * Sentinel substituted for page 2 Column B's `overridesColumnRaw` before the
     * structural comparison: the JSON byte layout (key order, spacing) is not
     * pinned, only its decoded content, which is asserted separately.
     */
    private const string PAGE2_COLUMN_B_OVERRIDES = '<<page2-column-b-overrides-json>>';

    public function testTwoPagesMigrateUnderTheirOwnParentsWithoutLeakage(): void
    {
        $pageId1 = $this->pageId('test_page');
        $pageId2 = $this->pageId('test_page_2');

        $this->seedSharedRowPage($pageId1);
        $this->publishPage1ToLive($pageId1);
        $this->seedDraftOnlyPage($pageId2);

        // Multi-page run: the base runMigration() is single-page, so drive the
        // service directly with both page IDs in one call.
        $failures = $this->createService()->run(
            self::DEFAULT_VIEWPORT,
            self::ZONE,
            self::VIEWPORT_KEY_MAP,
            dryRun: false,
            pageIds: [$pageId1, $pageId2],
            reconcileDisabledPages: true,
        );
        self::assertSame(0, $failures, 'Both pages migrate without failures');

        $page1Draft = MigrationTreeSnapshot::snapshotTree($pageId1, Page::class, self::ZONE, Versioned::DRAFT);
        $page1Live = MigrationTreeSnapshot::snapshotTree($pageId1, Page::class, self::ZONE, Versioned::LIVE);
        $page2Draft = MigrationTreeSnapshot::snapshotTree($pageId2, Page::class, self::ZONE, Versioned::DRAFT);
        $page2Live = MigrationTreeSnapshot::snapshotTree($pageId2, Page::class, self::ZONE, Versioned::LIVE);

        // ── Page 1: shared row, published ────────────────────────────
        self::assertSame($this->expectedPage1Tree(), $page1Draft);
        self::assertSame($this->expectedPage1Tree(), $page1Live, 'Page 1 publishes identically to LIVE');

        // ── Page 2: draft-only, with a per-viewport override ─────────
        // Column B's raw overrides JSON is format-sensitive; assert its decoded
        // content separately, then normalise it to a sentinel for the diff.
        $page2ColumnBRaw = $page2Draft[0]['rows'][0]['columns'][1]['overridesColumnRaw'] ?? null;
        self::assertIsString($page2ColumnBRaw, 'Page 2 Column B persists a non-null JSON overrides column');
        self::assertStringContainsString('"lg"', $page2ColumnBRaw, 'Page 2 Column B carries the lg override');
        $decoded = json_decode($page2ColumnBRaw, true);
        self::assertIsArray($decoded);
        self::assertSame(3, $decoded['lg']['width'] ?? null, 'Page 2 Column B lg override width is 3');

        $page2Draft[0]['rows'][0]['columns'][1]['overridesColumnRaw'] = self::PAGE2_COLUMN_B_OVERRIDES;
        self::assertSame($this->expectedPage2DraftTree(), $page2Draft);
        self::assertSame([], $page2Live, 'Page 2 was never published, so LIVE is empty');

        // ── Sort values ascending per level (page 2 has two columns) ──
        $page2ColumnSorts = array_map(
            static fn (array $column): int => $column['sort'],
            $page2Draft[0]['rows'][0]['columns'],
        );
        self::assertSame([1, 2], $page2ColumnSorts, 'Page 2 columns are sorted ascending');

        // ── No cross-page title leakage ──────────────────────────────
        self::assertSame(['P1 Body'], $this->allElementTitles($page1Draft), 'Page 1 holds only its own elements');
        self::assertSame(['P2 Body A', 'P2 Body B'], $this->allElementTitles($page2Draft), 'Page 2 holds only its own elements');

        // ── Polymorphic parent correctness (ParentClass:ParentID) ────
        // Each migrated Section points at its OWN page. On DRAFT both pages have
        // exactly one Section; the parents are page-1→p1 and page-2→p2.
        $draftRefs = $this->sectionParentRefs(Versioned::DRAFT);
        self::assertCount(2, $draftRefs, 'One Section per page on DRAFT');
        self::assertContains(['ParentClass' => Page::class, 'ParentID' => $pageId1], $draftRefs);
        self::assertContains(['ParentClass' => Page::class, 'ParentID' => $pageId2], $draftRefs);

        // OBSERVED-AND-PINNED: a Row delimiter shared across draft+live is NOT
        // re-created as a live-only Section. Only page 1 was published, so LIVE
        // holds EXACTLY ONE Section, under page 1 — no spurious duplicate.
        $liveRefs = $this->sectionParentRefs(Versioned::LIVE);
        self::assertSame(
            [['ParentClass' => Page::class, 'ParentID' => $pageId1]],
            $liveRefs,
            'Shared row publishes to exactly one LIVE Section under page 1',
        );

        // ── UseGrid flag per page per stage ──────────────────────────
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId1, Versioned::DRAFT));
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId1, Versioned::LIVE), 'Page 1 grid enabled on LIVE (published)');
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId2, Versioned::DRAFT));
        self::assertSame(0, MigrationTreeSnapshot::useGridFlag($pageId2, Versioned::LIVE), 'Page 2 never published; no live flag');
    }

    /**
     * Seed (BOTH stages) page 1: a Row delimiter present on draft AND live
     * (a "shared" row), plus one shared content element. The shared row exercises
     * the `$draftLegacyIds` guard — it must publish to a single LIVE Section.
     *
     * @param positive-int $pageId
     */
    private function seedSharedRowPage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID_1);

        // Row delimiter on BOTH stages — its CustomSectionClass becomes the
        // migrated Section's ExtraClass.
        $this->seeder->seedElement(1000, self::AREA_ID_1, self::ROW_CLASS, 1, [
            'Title' => 'P1 Row',
            'ExtraClass' => 'p1-row',
        ], 'draft');
        $this->seeder->seedRow(1000, isFluid: false, customSectionClass: 'p1-sec', stage: 'draft');
        $this->seeder->seedElement(1000, self::AREA_ID_1, self::ROW_CLASS, 1, [
            'Title' => 'P1 Row',
            'ExtraClass' => 'p1-row',
        ], 'live');
        $this->seeder->seedRow(1000, isFluid: false, customSectionClass: 'p1-sec', stage: 'live');

        // Shared content element on BOTH stages.
        $this->seeder->seedElement(1001, self::AREA_ID_1, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'P1 Body',
        ], 'draft');
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>p1</p>'], 'draft');
        $this->seeder->seedElement(1001, self::AREA_ID_1, self::CONTENT_CLASS, 2, [
            'SizeMD' => 6,
            'Title' => 'P1 Body',
        ], 'live');
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>p1</p>'], 'live');
    }

    /**
     * Seed (DRAFT stage only) page 2: a Row delimiter plus two distinct-width
     * content elements (so they do NOT group) — Column A (width 4, no override)
     * and Column B (width 8 with an `lg` per-viewport override) exercising the
     * GridSettingsOverrides JSON tri-state.
     *
     * @param positive-int $pageId
     */
    private function seedDraftOnlyPage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID_2);

        $this->seeder->seedElement(2000, self::AREA_ID_2, self::ROW_CLASS, 1, [
            'Title' => 'P2 Row',
            'ExtraClass' => 'p2-row',
        ]);
        $this->seeder->seedRow(2000, isFluid: false, customSectionClass: 'p2-sec');

        // Element A — width 4, no override → NULL overrides column.
        $this->seeder->seedElement(2001, self::AREA_ID_2, self::CONTENT_CLASS, 2, [
            'SizeMD' => 4,
            'Title' => 'P2 Body A',
        ]);
        $this->seeder->seedContentMedia(2001, ['HTML' => '<p>p2-a</p>']);

        // Element B — width 8 with an lg override (SizeLG 3) → non-null JSON.
        $this->seeder->seedElement(2002, self::AREA_ID_2, self::CONTENT_CLASS, 3, [
            'SizeMD' => 8,
            'SizeLG' => 3,
            'Title' => 'P2 Body B',
        ]);
        $this->seeder->seedContentMedia(2002, ['HTML' => '<p>p2-b</p>']);
    }

    /**
     * Publish page 1 to LIVE and flag legacy grid on `Page_Live`, mirroring the
     * draft↔live characterization setup, so the migration exercises the published
     * live path for the shared row.
     *
     * @param positive-int $pageId
     */
    private function publishPage1ToLive(int $pageId): void
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->UseGrid = false;
        $page->write();
        $page->publishSingle();
        DB::prepared_query(
            'UPDATE "Page_Live" SET "UseElementalGrid" = 1, "ElementalAreaID" = ? WHERE "ID" = ?',
            [self::AREA_ID_1, $pageId],
        );
    }

    /**
     * Pinned tree for page 1 (identical on DRAFT and LIVE): one Section
     * ('p1-sec'), one Row ('P1 Row'/'p1-row'), one Column (width 6, no override),
     * one content element ('P1 Body').
     *
     * @return list<array{
     *     extraClass: string,
     *     sort: int,
     *     rows: list<array{
     *         title: string,
     *         extraClass: string,
     *         sort: int,
     *         columns: list<array{
     *             sort: int,
     *             gridDefault: array{width: int, offset: int, visible: bool},
     *             overridesColumnRaw: string|null,
     *             elements: list<array{
     *                 title: string,
     *                 showTitle: bool,
     *                 titleTag: string,
     *                 titleClass: string,
     *                 extraClass: string,
     *                 sort: int,
     *                 className: string,
     *                 html: string|null,
     *             }>,
     *         }>,
     *     }>,
     * }>
     */
    private function expectedPage1Tree(): array
    {
        return [
            [
                'extraClass' => 'p1-sec',
                'sort' => 1,
                'rows' => [
                    [
                        'title' => 'P1 Row',
                        'extraClass' => 'p1-row',
                        'sort' => 1,
                        'columns' => [
                            [
                                'sort' => 1,
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'overridesColumnRaw' => null,
                                'elements' => [
                                    [
                                        'title' => 'P1 Body',
                                        'showTitle' => false,
                                        'titleTag' => 'h2',
                                        'titleClass' => '',
                                        'extraClass' => '',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>p1</p>',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Pinned DRAFT tree for page 2: one Section ('p2-sec'), one Row, two Columns
     * (A width 4 / null override, B width 8 / lg override normalised to the
     * sentinel).
     *
     * @return list<array{
     *     extraClass: string,
     *     sort: int,
     *     rows: list<array{
     *         title: string,
     *         extraClass: string,
     *         sort: int,
     *         columns: list<array{
     *             sort: int,
     *             gridDefault: array{width: int, offset: int, visible: bool},
     *             overridesColumnRaw: string|null,
     *             elements: list<array{
     *                 title: string,
     *                 showTitle: bool,
     *                 titleTag: string,
     *                 titleClass: string,
     *                 extraClass: string,
     *                 sort: int,
     *                 className: string,
     *                 html: string|null,
     *             }>,
     *         }>,
     *     }>,
     * }>
     */
    private function expectedPage2DraftTree(): array
    {
        return [
            [
                'extraClass' => 'p2-sec',
                'sort' => 1,
                'rows' => [
                    [
                        'title' => 'P2 Row',
                        'extraClass' => 'p2-row',
                        'sort' => 1,
                        'columns' => [
                            [
                                'sort' => 1,
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'overridesColumnRaw' => null,
                                'elements' => [
                                    [
                                        'title' => 'P2 Body A',
                                        'showTitle' => false,
                                        'titleTag' => 'h2',
                                        'titleClass' => '',
                                        'extraClass' => '',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>p2-a</p>',
                                    ],
                                ],
                            ],
                            [
                                'sort' => 2,
                                'gridDefault' => ['width' => 8, 'offset' => 0, 'visible' => true],
                                'overridesColumnRaw' => self::PAGE2_COLUMN_B_OVERRIDES,
                                'elements' => [
                                    [
                                        'title' => 'P2 Body B',
                                        'showTitle' => false,
                                        'titleTag' => 'h2',
                                        'titleClass' => '',
                                        'extraClass' => '',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>p2-b</p>',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Every element Title in a snapshot tree, in tree order.
     *
     * @param list<array<string, mixed>> $tree
     *
     * @return list<string>
     */
    private function allElementTitles(array $tree): array
    {
        $titles = [];
        foreach ($tree as $section) {
            foreach ($section['rows'] as $row) {
                foreach ($row['columns'] as $column) {
                    foreach ($column['elements'] as $element) {
                        $titles[] = (string) $element['title'];
                    }
                }
            }
        }

        return $titles;
    }

    /**
     * Parent references for every migrated Section on a stage, read directly from
     * the stage-appropriate base GridElement table (the snapshot helper
     * intentionally does not expose IDs/parents).
     *
     * @param non-empty-string $stage
     *
     * @return list<array{ParentClass: string, ParentID: int}>
     */
    private function sectionParentRefs(string $stage): array
    {
        $table = $stage === Versioned::LIVE
            ? 'WeDevelop_Grid_GridElement_Live'
            : 'WeDevelop_Grid_GridElement';

        $query = DB::prepared_query(
            \sprintf('SELECT "ParentClass", "ParentID" FROM "%s" WHERE "ClassName" = ? ORDER BY "ID" ASC', $table),
            [Section::class],
        );

        $refs = [];
        foreach ($query as $row) {
            $refs[] = [
                'ParentClass' => (string) $row['ParentClass'],
                'ParentID' => (int) $row['ParentID'],
            ];
        }

        return $refs;
    }
}
