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

#[CoversClass(RowPerSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
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

    private static function e(int $width): LegacyElement
    {
        $id = ++self::$nextId;
        return LegacyElementFactory::content($id, $id, ['sizeFields' => ['MD' => $width]]);
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
     * Expected section shape (fields default to '' / 0 if omitted):
     *   ['extraClass' => '', 'rows' => [['title' => '', 'extraClass' => '', 'columnWidths' => [8, 4]]]]
     *
     * Sort values are deterministic: section sort = index+1, row sort = always 1,
     * column sort = index+1. These are asserted automatically.
     *
     * @return iterable<string, array{list<LegacyElement>, string, list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columnWidths: list<int>}>}>}>
     */
    public static function hierarchyProvider(): iterable
    {
        // ── Structural cases ─────────────────────────────────────

        self::$nextId = 0;
        yield 'single row, single element' => [
            [self::r(), self::e(12)],
            'main',
            [['rows' => [['columnWidths' => [12]]]]],
        ];

        self::$nextId = 0;
        yield 'single row, three elements' => [
            [self::r(), self::e(4), self::e(4), self::e(4)],
            'main',
            [['rows' => [['columnWidths' => [4, 4, 4]]]]],
        ];

        self::$nextId = 0;
        yield 'two rows, varying element counts' => [
            [self::r(), self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            [
                ['rows' => [['columnWidths' => [8, 4]]]],
                ['rows' => [['columnWidths' => [12]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'orphans only, no rows' => [
            [self::e(6), self::e(6)],
            'main',
            [['rows' => [['columnWidths' => [6, 6]]]]],
        ];

        self::$nextId = 0;
        yield 'three rows: 3 elements, 1 element, empty' => [
            [self::r(), self::e(4), self::e(4), self::e(4), self::r(), self::e(12), self::r()],
            'main',
            [
                ['rows' => [['columnWidths' => [4, 4, 4]]]],
                ['rows' => [['columnWidths' => [12]]]],
                ['rows' => [['columnWidths' => []]]],
            ],
        ];

        self::$nextId = 0;
        yield 'adjacent empty rows then elements' => [
            [self::r(), self::r(), self::e(6), self::e(6)],
            'main',
            [
                ['rows' => [['columnWidths' => []]]],
                ['rows' => [['columnWidths' => [6, 6]]]],
            ],
        ];

        self::$nextId = 0;
        yield 'orphans, row with trailing elements' => [
            [self::e(3), self::r(), self::e(6), self::e(3), self::e(3)],
            'main',
            [
                ['rows' => [['columnWidths' => [3]]]],
                ['rows' => [['columnWidths' => [6, 3, 3]]]],
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
                    'rows' => [['title' => 'Row Title', 'extraClass' => 'row-extra', 'columnWidths' => [12]]],
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
                    'rows' => [['title' => '', 'extraClass' => '', 'columnWidths' => [8, 4]]],
                ],
                [
                    'extraClass' => 'explicit-section',
                    'rows' => [['title' => 'Explicit', 'extraClass' => 'explicit-extra', 'columnWidths' => [12]]],
                ],
            ],
        ];

        // ── Zone case ────────────────────────────────────────────

        self::$nextId = 0;
        yield 'zone is passed through to all sections' => [
            [self::r(), self::r()],
            'sidebar',
            [
                ['rows' => [['columnWidths' => []]]],
                ['rows' => [['columnWidths' => []]]],
            ],
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @param list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columnWidths: list<int>}>}> $expectedSections
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

                // RowPerSection: each section has exactly 1 row, sort is always 1
                self::assertSame(1, $row->sort, "{$rowPath}: sort");
                self::assertSame($expectedRow['title'] ?? '', $row->title, "{$rowPath}: title");
                self::assertSame($expectedRow['extraClass'] ?? '', $row->extraClass, "{$rowPath}: extraClass");

                $expectedWidths = $expectedRow['columnWidths'];
                self::assertCount(\count($expectedWidths), $row->columns, "{$rowPath}: column count");

                foreach ($row->columns as $ci => $column) {
                    self::assertSame($ci + 1, $column->sort, "{$rowPath} > Column {$ci}: sort");
                    self::assertSame(
                        $expectedWidths[$ci],
                        $column->gridSettings->default->width,
                        "{$rowPath} > Column {$ci}: width",
                    );
                }
            }
        }
    }
}
