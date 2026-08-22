<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Migration;

use Page;
use PHPUnit\Framework\Attributes\CoversNothing;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Migration\Support\MigrationTreeSnapshot;
use WeDevelop\Grid\Model\Section;

/**
 * Golden-master (characterization) test for the simplest migration scenario:
 * a single page whose legacy content exists on the DRAFT stage only.
 *
 * It pins the CURRENT observed output of the migration pipeline; it does not
 * assert intended behaviour. If it fails, the expected snapshot is mis-pinned —
 * correct the expectation, never touch `src/`.
 *
 * Perturbation thought-experiment (why this snapshot is a real gate, not a
 * smoke test). The assertion would FAIL if any of these WS5/WS6 risk surfaces
 * regressed:
 *  (a) The override-collapsing logic in FieldMapper::mapGridSettings changed so
 *      Column C's `lg` override were dropped — `overridesColumnRaw` would flip
 *      from a JSON string to `null`.
 *  (b) DBGridSettings::applyGridSettings ever wrote `"{}"` instead of `null`
 *      for an empty override map — Columns A and B's `overridesColumnRaw` would
 *      flip from `null` to a non-null string.
 *  (c) The grouping boundary in ElementGrouper changed so A and B merged into
 *      one column — the column count would drop from 3 to 2.
 *  (d) Auto-scaffold suppression broke during the migration write — extra
 *      scaffolded Rows/Columns would appear in the tree.
 */
#[CoversNothing]
final class CharacterizationDraftOnlyTest extends CharacterizationTestCase
{
    private const int AREA_ID = 100;

    /**
     * Sentinel substituted for Column C's `overridesColumnRaw` before the
     * structural comparison: the JSON byte layout (key order, spacing) is not
     * pinned, only its decoded content, which is asserted separately.
     */
    private const string COLUMN_C_OVERRIDES = '<<column-c-overrides-json>>';

    public function testDraftOnlyPageMigratesToPinnedTree(): void
    {
        $pageId = $this->pageId();
        $this->seedDraftOnlyPage($pageId);

        // Clear the page's UseGrid flag on DRAFT before migrating so the
        // post-migration `UseGrid === 1` assertion proves the migration enabled
        // it, rather than passing on the Boolean(1) DB default (the legacy seeder
        // never writes UseGrid).
        $page = $this->objFromFixture(Page::class, 'test_page');
        $page->UseGrid = false;
        $page->write();

        $this->runMigration($pageId);

        $tree = MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::DRAFT);

        // Column C's raw overrides JSON is format-sensitive; assert its content
        // separately, then normalise it to a sentinel for the structural diff.
        MigrationTreeSnapshot::assertLgOverrideWidth($tree[0]['rows'][0]['columns'][2]['overridesColumnRaw'] ?? null, 3);

        $tree[0]['rows'][0]['columns'][2]['overridesColumnRaw'] = self::COLUMN_C_OVERRIDES;

        self::assertSame($this->expectedDraftTree(), $tree);

        // The page is grid-enabled on DRAFT only — no live content means
        // setUseGridOnPage runs with includeLive: false.
        self::assertSame(1, MigrationTreeSnapshot::useGridFlag($pageId, Versioned::DRAFT));
        self::assertTrue(
            MigrationTreeSnapshot::recordExistsOnStage(Section::class, $pageId, Page::class, self::ZONE, Versioned::DRAFT),
            'The migrated Section exists on DRAFT',
        );

        // Nothing exists on LIVE: no published sections and no live UseGrid flag.
        self::assertSame([], MigrationTreeSnapshot::snapshotTree($pageId, Page::class, self::ZONE, Versioned::LIVE));
        self::assertSame(0, MigrationTreeSnapshot::useGridFlag($pageId, Versioned::LIVE));
        self::assertFalse(
            MigrationTreeSnapshot::recordExistsOnStage(Section::class, $pageId, Page::class, self::ZONE, Versioned::LIVE),
            'No Section is published to LIVE for a draft-only page',
        );
    }

    /**
     * Seed (DRAFT stage only): one Row delimiter carrying the section/row
     * classes, then three content elements with DISTINCT widths so they do not
     * group — Column A (width 8, offset 2), Column B (width 4), and Column C
     * (width 6 with an `lg` per-viewport override) which exercises the
     * GridSettingsOverrides JSON tri-state.
     *
     * @param positive-int $pageId
     */
    private function seedDraftOnlyPage(int $pageId): void
    {
        $this->seeder->seedPage($pageId, self::AREA_ID);

        // Row delimiter: Row title 'R'/extraClass 'row-x'; its CustomSectionClass
        // 'sec-x' becomes the migrated Section's ExtraClass.
        $this->seeder->seedElement(1000, self::AREA_ID, self::ROW_CLASS, 1, [
            'Title' => 'R',
            'ExtraClass' => 'row-x',
        ]);
        $this->seeder->seedRow(1000, isFluid: false, customSectionClass: 'sec-x');

        // Element A — width 8, offset 2, shown title h3.
        $this->seeder->seedElement(1001, self::AREA_ID, self::CONTENT_CLASS, 2, [
            'SizeMD' => 8,
            'OffsetMD' => 2,
            'Title' => 'A',
            'ShowTitle' => 1,
            'TitleTag' => 'h3',
            'ExtraClass' => 'a-x',
        ]);
        $this->seeder->seedContentMedia(1001, ['HTML' => '<p>A</p>']);

        // Element B — width 4, distinct from A so they do not group.
        $this->seeder->seedElement(1002, self::AREA_ID, self::CONTENT_CLASS, 3, [
            'SizeMD' => 4,
            'Title' => 'B',
        ]);
        $this->seeder->seedContentMedia(1002, ['HTML' => '<p>B</p>']);

        // Element C — width 6 with an lg override (SizeLG 3) → non-null overrides JSON.
        $this->seeder->seedElement(1003, self::AREA_ID, self::CONTENT_CLASS, 4, [
            'SizeMD' => 6,
            'SizeLG' => 3,
            'Title' => 'C',
        ]);
        $this->seeder->seedContentMedia(1003, ['HTML' => '<p>C</p>']);
    }

    /**
     * @return list<array{
     *     extraClass: string,
     *     sort: int,
     *     rows: list<array{
     *         title: string,
     *         extraClass: string,
     *         sort: int,
     *         columns: list<array{
     *             sort: int,
     *             gridDefault: array{width: positive-int, offset: int<0, max>, visible: bool},
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
    private function expectedDraftTree(): array
    {
        return [
            [
                'extraClass' => 'sec-x',
                'sort' => 1,
                'rows' => [
                    [
                        'title' => 'R',
                        'extraClass' => 'row-x',
                        'sort' => 1,
                        'columns' => [
                            [
                                'sort' => 1,
                                'gridDefault' => ['width' => 8, 'offset' => 2, 'visible' => true],
                                'overridesColumnRaw' => null,
                                'elements' => [
                                    [
                                        'title' => 'A',
                                        'showTitle' => true,
                                        'titleTag' => 'h3',
                                        'titleClass' => '',
                                        'extraClass' => 'a-x',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>A</p>',
                                    ],
                                ],
                            ],
                            [
                                'sort' => 2,
                                'gridDefault' => ['width' => 4, 'offset' => 0, 'visible' => true],
                                'overridesColumnRaw' => null,
                                'elements' => [
                                    [
                                        'title' => 'B',
                                        'showTitle' => false,
                                        'titleTag' => 'h2',
                                        'titleClass' => '',
                                        'extraClass' => '',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>B</p>',
                                    ],
                                ],
                            ],
                            [
                                'sort' => 3,
                                'gridDefault' => ['width' => 6, 'offset' => 0, 'visible' => true],
                                'overridesColumnRaw' => self::COLUMN_C_OVERRIDES,
                                'elements' => [
                                    [
                                        'title' => 'C',
                                        'showTitle' => false,
                                        'titleTag' => 'h2',
                                        'titleClass' => '',
                                        'extraClass' => '',
                                        'sort' => 1,
                                        'className' => ContentElement::class,
                                        'html' => '<p>C</p>',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
