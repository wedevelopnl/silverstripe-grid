<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementBuilder;
use WeDevelop\Grid\Value\GridSettings;

#[CoversClass(RowPerSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
#[CoversClass(GridSettings::class)]
final class RowPerSectionStrategyTest extends TestCase
{
    use AssertsSectionHierarchy;

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

    /**
     * Each case yields: [elements, zone, expected sections].
     *
     * Expected column shape: ['w' => width, 'o' => offset]; offset defaults to 0
     * if omitted. Every content element becomes its own column, in input order.
     *
     * Sort values are deterministic and asserted automatically:
     * section sort = index+1, row sort = always 1, column sort = index+1.
     *
     * @return iterable<string, array{list<LegacyElement>, string, list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int}>}>}>}>
     */
    public static function hierarchyProvider(): iterable
    {
        $b = new LegacyElementBuilder();
        yield 'single row, single element' => [
            [$b->r(), $b->e(12)],
            'main',
            [['rows' => [['columns' => [['w' => 12]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'three one-third elements sit side by side' => [
            [$b->r(), $b->e(4), $b->e(4), $b->e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 4], ['w' => 4], ['w' => 4]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'two full-width elements each get their own column' => [
            [$b->r(), $b->e(12), $b->e(12)],
            'main',
            [['rows' => [['columns' => [['w' => 12], ['w' => 12]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'mixed widths keep their own width and order' => [
            [$b->r(), $b->e(4), $b->e(6), $b->e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 4], ['w' => 6], ['w' => 4]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'six one-third elements become six columns' => [
            [$b->r(), $b->e(4), $b->e(4), $b->e(4), $b->e(4), $b->e(4), $b->e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 4], ['w' => 4], ['w' => 4], ['w' => 4], ['w' => 4], ['w' => 4]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'two rows, varying element counts' => [
            [$b->r(), $b->e(8), $b->e(4), $b->r(), $b->e(12)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 8], ['w' => 4]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'orphans with same width each get their own column' => [
            [$b->e(6), $b->e(6)],
            'main',
            [['rows' => [['columns' => [['w' => 6], ['w' => 6]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'three rows: 3 elements, 1 element, trailing empty row dropped' => [
            [$b->r(), $b->e(4), $b->e(4), $b->e(4), $b->r(), $b->e(12), $b->r()],
            'main',
            [
                ['rows' => [['columns' => [['w' => 4], ['w' => 4], ['w' => 4]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
                // The trailing empty row delimiter produces no columns and is dropped.
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'leading empty rows dropped, then elements' => [
            [$b->r(), $b->r(), $b->e(6), $b->e(6)],
            'main',
            [
                // The two leading empty delimiters are dropped; only the content row remains.
                ['rows' => [['columns' => [['w' => 6], ['w' => 6]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'orphans, row with trailing elements' => [
            [$b->e(3), $b->r(), $b->e(6), $b->e(3), $b->e(3)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 3]]]]],
                ['rows' => [['columns' => [['w' => 6], ['w' => 3], ['w' => 3]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'elements with offsets' => [
            [$b->r(), $b->e(8, 2), $b->e(4)],
            'main',
            [['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'mixed offsets across rows' => [
            [$b->r(), $b->e(6, 3), $b->r(), $b->e(4, 1), $b->e(4, 1)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 6, 'o' => 3]]]]],
                ['rows' => [['columns' => [['w' => 4, 'o' => 1], ['w' => 4, 'o' => 1]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'orphan elements with offsets' => [
            [$b->e(8, 2), $b->e(4), $b->r(), $b->e(12)],
            'main',
            [
                ['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]],
                ['rows' => [['columns' => [['w' => 12]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'row fields map to section and row' => [
            [$b->r('Row Title', 'row-extra', 'section-class'), $b->e(12)],
            'main',
            [
                [
                    'extraClass' => 'section-class',
                    'rows' => [['title' => 'Row Title', 'extraClass' => 'row-extra', 'columns' => [['w' => 12]]]],
                ],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'implicit group before first row has default field values' => [
            [$b->e(8), $b->e(4), $b->r('Explicit', 'explicit-extra', 'explicit-section'), $b->e(12)],
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

        $b = new LegacyElementBuilder();
        yield 'invalid grid settings are clamped to valid range' => [
            [$b->r(), $b->e(15, 14)],
            'main',
            [['rows' => [['columns' => [['w' => 12, 'o' => 0]]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'zone is passed through to all sections' => [
            [$b->r(), $b->e(6), $b->r(), $b->e(4)],
            'sidebar',
            [
                ['rows' => [['columns' => [['w' => 6]]]]],
                ['rows' => [['columns' => [['w' => 4]]]]],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'a page of only empty row delimiters produces no sections' => [
            [$b->r(), $b->r()],
            'sidebar',
            [],
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @param list<array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int}>}>}> $expectedSections
     */
    #[DataProvider('hierarchyProvider')]
    public function testHierarchy(array $elements, string $zone, array $expectedSections): void
    {
        $sections = $this->strategy->buildHierarchy($elements, pageId: 1, zone: $zone);

        // RowPerSection wraps each row in its own section, so every row sorts at 1.
        self::assertSectionsMatchSpec($elements, $sections, $zone, $expectedSections, rowSortFollowsIndex: false);
    }
}
