<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\MigrationSection;

/**
 * Shared spec-walking assertions for the strategy hierarchy tests.
 *
 * Column spec: ['w' => width, 'o' => offset, 'n' => element count];
 * offset defaults to 0 and element count to 1 when omitted.
 */
trait AssertsSectionHierarchy
{
    /**
     * @param list<MigrationSection> $sections
     * @param list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int, n?: int}>}>}> $expectedSections
     * @param bool $rowSortFollowsIndex true when rows sort sequentially within their section (AllRows);
     *                                  false when every row sorts at 1 (RowPerSection)
     */
    private static function assertSectionsMatchSpec(
        array $sections,
        string $zone,
        array $expectedSections,
        bool $rowSortFollowsIndex,
    ): void {
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

                self::assertSame($rowSortFollowsIndex ? $ri + 1 : 1, $row->sort, "{$rowPath}: sort");
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
