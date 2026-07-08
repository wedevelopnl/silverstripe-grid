<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;
use WeDevelop\Grid\Value\GridSettings;

#[CoversClass(RowPerSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
#[CoversClass(GridSettings::class)]
final class RowPerSectionStrategyTest extends TestCase
{
    private RowPerSectionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new RowPerSectionStrategy(
            grouper: new ElementGrouper(),
            mapper: new FieldMapper(),
            defaultViewport: 'MD',
            viewportKeyMap: ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'],
        );
    }

    // ─── Factory helpers ─────────────────────────────────────────

    private static int $nextId = 0;

    /**
     * Content element with only default-viewport grid settings.
     *
     * @param array<string, int> $sizeFields    Override size fields (default: ['MD' => $width])
     * @param array<string, int> $offsetFields   Override offset fields (default: ['MD' => $offset])
     * @param array<string, ?string> $visibilityFields Override visibility fields
     */
    private static function e(
        int $width,
        int $offset = 0,
        array $sizeFields = [],
        array $offsetFields = [],
        array $visibilityFields = [],
    ): LegacyElement {
        $id = ++self::$nextId;
        return LegacyElementFactory::content($id, $id, [
            'sizeFields' => $sizeFields !== [] ? $sizeFields : ['MD' => $width],
            'offsetFields' => $offsetFields !== [] ? $offsetFields : ['MD' => $offset],
            'visibilityFields' => $visibilityFields,
        ]);
    }

    private static function r(string $title = '', string $extraClass = '', string $sectionClass = ''): LegacyElement
    {
        $id = ++self::$nextId;

        return new LegacyElement(
            id: $id,
            className: 'Test\Row',
            title: $title,
            showTitle: false,
            titleTag: 'h2',
            titleClass: '',
            sort: $id,
            extraClass: $extraClass,
            isRow: true,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
            rowData: new LegacyRowData(customSectionClass: $sectionClass),
        );
    }

    // ─── Data provider ───────────────────────────────────────────

    /**
     * Each case yields: [elements, zone, expected sections].
     *
     * Expected column shape: ['w' => width, 'o' => offset, 'n' => element count].
     * Offset defaults to 0 and element count defaults to 1 if omitted.
     *
     * Sort values are deterministic and asserted automatically:
     * section sort = index+1, row sort = always 1, column sort = index+1.
     *
     * @return iterable<string, array{list<LegacyElement>, string, list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int, n?: int}>}>}>}>
     */
    public static function hierarchyProvider(): iterable
    {
        // ── Structural cases ─────────────────────────────────────

        self::$nextId = 0;
        yield 'single row, single element' => [
            [self::r(), self::e(12)],
            'main',
            [['rows' => [['columns' => [['w' => 12]]]]]],
        ];

        self::$nextId = 0;
        yield 'single row, three elements with same width are grouped' => [
            [self::r(), self::e(4), self::e(4), self::e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 4, 'n' => 3]]]]]],
        ];

        self::$nextId = 0;
        yield 'two rows, varying element counts' => [
            [self::r(), self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 8], ['w' => 4]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'orphans with same width are grouped' => [
            [self::e(6), self::e(6)],
            'main',
            [['rows' => [['columns' => [['w' => 6, 'n' => 2]]]]]],
        ];

        self::$nextId = 0;
        yield 'three rows: 3 same elements grouped, 1 element, trailing empty row dropped' => [
            [self::r(), self::e(4), self::e(4), self::e(4), self::r(), self::e(12), self::r()],
            'main',
            [
                ['rows' => [['columns' => [['w' => 4, 'n' => 3]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
                // The trailing empty row delimiter produces no columns and is dropped.
            ],
        ];

        self::$nextId = 0;
        yield 'leading empty rows dropped, then elements' => [
            [self::r(), self::r(), self::e(6), self::e(6)],
            'main',
            [
                // The two leading empty delimiters are dropped; only the content row remains.
                ['rows' => [['columns' => [['w' => 6, 'n' => 2]]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'orphans, row with trailing elements' => [
            [self::e(3), self::r(), self::e(6), self::e(3), self::e(3)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 3]]]]],
                ['rows' => [['columns' => [['w' => 6], ['w' => 3, 'n' => 2]]]]],
            ],
        ];

        // ── Offset cases ─────────────────────────────────────────

        self::$nextId = 0;
        yield 'elements with offsets' => [
            [self::r(), self::e(8, 2), self::e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]]],
        ];

        self::$nextId = 0;
        yield 'mixed offsets across rows' => [
            [self::r(), self::e(6, 3), self::r(), self::e(4, 1), self::e(4, 1)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 6, 'o' => 3]]]]],
                ['rows' => [['columns' => [['w' => 4, 'o' => 1, 'n' => 2]]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'orphan elements with offsets' => [
            [self::e(8, 2), self::e(4), self::r(), self::e(12)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
            ],
        ];

        // ── Field mapping cases ──────────────────────────────────

        self::$nextId = 0;
        yield 'row fields map to section and row' => [
            [self::r('Row Title', 'row-extra', 'section-class'), self::e(12)],
            'main',
            [
                [
                    'extraClass' => 'section-class',
                    'rows' => [['title' => 'Row Title', 'extraClass' => 'row-extra', 'columns' => [['w' => 12]]]],
                ],
            ],
        ];

        self::$nextId = 0;
        yield 'implicit group before first row has default field values' => [
            [self::e(8), self::e(4), self::r('Explicit', 'explicit-extra', 'explicit-section'), self::e(12)],
            'main',
            [
                [
                    'extraClass' => '',
                    'rows' => [['title' => '', 'extraClass' => '', 'columns' => [['w' => 8], ['w' => 4]]]],
                ],
                [
                    'extraClass' => 'explicit-section',
                    'rows' => [['title' => 'Explicit', 'extraClass' => 'explicit-extra', 'columns' => [['w' => 12]]]],
                ],
            ],
        ];

        // ── Clamping case ────────────────────────────────────────

        self::$nextId = 0;
        yield 'invalid grid settings are clamped to valid range' => [
            [self::r(), self::e(15, 14)],
            'main',
            [['rows' => [['columns' => [['w' => 12, 'o' => 0]]]]]],
        ];

        // ── Zone case ────────────────────────────────────────────

        self::$nextId = 0;
        yield 'zone is passed through to all sections' => [
            [self::r(), self::e(6), self::r(), self::e(4)],
            'sidebar',
            [
                ['rows' => [['columns' => [['w' => 6]]]]],
                ['rows' => [['columns' => [['w' => 4]]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'a page of only empty row delimiters produces no sections' => [
            [self::r(), self::r()],
            'sidebar',
            [],
        ];

        // ── Grouping cases ───────────────────────────────────────

        self::$nextId = 0;
        yield 'alternating widths prevent grouping' => [
            [self::r(), self::e(6), self::e(4), self::e(6), self::e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 6], ['w' => 4], ['w' => 6], ['w' => 4]]]]]],
        ];

        self::$nextId = 0;
        yield 'same width different offset prevents grouping' => [
            [self::r(), self::e(6, 0), self::e(6, 3)],
            'main',
            [['rows' => [['columns' => [['w' => 6], ['w' => 6, 'o' => 3]]]]]],
        ];

        self::$nextId = 0;
        yield 'viewport override difference prevents grouping' => [
            [
                self::r(),
                self::e(6, 0, ['MD' => 6], ['MD' => 0]),
                self::e(6, 0, ['MD' => 6, 'SM' => 12], ['MD' => 0, 'SM' => 0]),
                self::e(6, 0, ['MD' => 6], ['MD' => 0]),
            ],
            'main',
            [['rows' => [['columns' => [['w' => 6], ['w' => 6], ['w' => 6]]]]]],
        ];

        self::$nextId = 0;
        yield 'visibility override difference prevents grouping' => [
            [
                self::r(),
                self::e(6, 0, ['MD' => 6], ['MD' => 0], []),
                self::e(6, 0, ['MD' => 6], ['MD' => 0], ['SM' => 'hidden']),
                self::e(6, 0, ['MD' => 6], ['MD' => 0], []),
            ],
            'main',
            [['rows' => [['columns' => [['w' => 6], ['w' => 6], ['w' => 6]]]]]],
        ];

        self::$nextId = 0;
        yield 'consecutive same then different then same splits correctly' => [
            [self::r(), self::e(6), self::e(6), self::e(4), self::e(6), self::e(6)],
            'main',
            [['rows' => [['columns' => [['w' => 6, 'n' => 2], ['w' => 4], ['w' => 6, 'n' => 2]]]]]],
        ];

        self::$nextId = 0;
        yield 'all elements identical config produce single column' => [
            [self::r(), self::e(12), self::e(12), self::e(12), self::e(12)],
            'main',
            [['rows' => [['columns' => [['w' => 12, 'n' => 4]]]]]],
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @param list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int, n?: int}>}>}> $expectedSections
     */
    #[DataProvider('hierarchyProvider')]
    public function testHierarchy(array $elements, string $zone, array $expectedSections): void
    {
        $sections = $this->strategy->buildHierarchy($elements, pageId: 1, zone: $zone);

        self::assertCount(\count($expectedSections), $sections, 'Section count');

        foreach ($sections as $si => $section) {
            $expected = $expectedSections[$si];
            $path = "Section {$si}";

            self::assertSame($zone, $section->zone, "{$path}: zone");
            self::assertSame($si + 1, $section->sort, "{$path}: sort");
            self::assertSame($expected['extraClass'] ?? '', $section->extraClass, "{$path}: extraClass");

            $expectedRows = $expected['rows'];
            self::assertCount(\count($expectedRows), $section->rows, "{$path}: row count");

            foreach ($section->rows as $ri => $row) {
                $expectedRow = $expectedRows[$ri];
                $rowPath = "{$path} > Row {$ri}";

                self::assertSame(1, $row->sort, "{$rowPath}: sort");
                self::assertSame($expectedRow['title'] ?? '', $row->title, "{$rowPath}: title");
                self::assertSame($expectedRow['extraClass'] ?? '', $row->extraClass, "{$rowPath}: extraClass");

                $expectedColumns = $expectedRow['columns'];
                self::assertCount(\count($expectedColumns), $row->columns, "{$rowPath}: column count");

                foreach ($row->columns as $ci => $column) {
                    $colPath = "{$rowPath} > Column {$ci}";
                    $expectedCol = $expectedColumns[$ci];

                    self::assertSame($ci + 1, $column->sort, "{$colPath}: sort");
                    self::assertSame($expectedCol['w'], $column->gridSettings->default->width, "{$colPath}: width");
                    self::assertSame($expectedCol['o'] ?? 0, $column->gridSettings->default->offset, "{$colPath}: offset");
                    self::assertCount($expectedCol['n'] ?? 1, $column->elements, "{$colPath}: element count");
                }
            }
        }
    }
}
