<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;
use WeDevelop\Grid\Value\GridSettings;

#[CoversClass(AllRowsInSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
#[CoversClass(GridSettings::class)]
final class AllRowsInSectionStrategyTest extends TestCase
{
    private AllRowsInSectionStrategy $strategy;

    /** @var MockObject&LoggerInterface */
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->strategy = new AllRowsInSectionStrategy(
            grouper: new ElementGrouper(),
            mapper: new FieldMapper(),
            defaultViewport: 'MD',
            viewportKeyMap: ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'],
            logger: $this->logger,
        );
    }

    // ─── Warning behavior (needs logger mock — cannot be data provider) ──

    public function testLaterRowWithDifferentCustomSectionClassLogsWarning(): void
    {
        $row1 = self::r('', '', 'first-class');
        $row2 = self::r('', '', 'second-class');

        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::stringContains('customSectionClass'));

        // Each row needs a content element, or the empty row groups are dropped
        // and no section is produced.
        $sections = $this->strategy->buildHierarchy(
            [$row1, self::e(6), $row2, self::e(4)],
            pageId: 10,
            zone: 'main',
        );

        self::assertSame('first-class', $sections[0]->extraClass);
    }

    public function testNoWarningWhenAllRowsHaveSameCustomSectionClass(): void
    {
        $row1 = self::r('', '', 'same-class');
        $row2 = self::r('', '', 'same-class');

        $this->logger->expects(self::never())->method('warning');

        $this->strategy->buildHierarchy([$row1, self::e(6), $row2, self::e(4)], pageId: 10, zone: 'main');
    }

    public function testEmptyElementsReturnsNoSections(): void
    {
        self::assertSame([], $this->strategy->buildHierarchy([], pageId: 1, zone: 'main'));
    }

    // ─── Factory helpers ─────────────────────────────────────────

    private static int $nextId = 0;

    /**
     * @param array<string, int> $sizeFields
     * @param array<string, int> $offsetFields
     * @param array<string, ?string> $visibilityFields
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
     * Each case yields: [elements, zone, expected section spec].
     *
     * AllRows always produces exactly 1 section. Column spec: ['w' => width, 'o' => offset, 'n' => element count].
     * Offset defaults to 0 and element count defaults to 1 if omitted.
     *
     * @return iterable<string, array{list<LegacyElement>, string, array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int, n?: int}>}>}}>
     */
    public static function hierarchyProvider(): iterable
    {
        // ── Structural cases ─────────────────────────────────────

        self::$nextId = 0;
        yield 'single row, three elements with same width are grouped' => [
            [self::r(), self::e(4), self::e(4), self::e(4)],
            'main',
            ['rows' => [['columns' => [['w' => 4, 'n' => 3]]]]],
        ];

        self::$nextId = 0;
        yield 'two rows, varying element counts' => [
            [self::r(), self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            ['rows' => [['columns' => [['w' => 8], ['w' => 4]]], ['columns' => [['w' => 12]]]]],
        ];

        self::$nextId = 0;
        yield 'orphans before first row' => [
            [self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            ['rows' => [['columns' => [['w' => 8], ['w' => 4]]], ['columns' => [['w' => 12]]]]],
        ];

        self::$nextId = 0;
        yield 'orphans only with same width are grouped' => [
            [self::e(6), self::e(6)],
            'main',
            ['rows' => [['columns' => [['w' => 6, 'n' => 2]]]]],
        ];

        self::$nextId = 0;
        yield 'three rows: 3 same elements grouped, 1 element, trailing empty row dropped' => [
            [self::r(), self::e(4), self::e(4), self::e(4), self::r(), self::e(12), self::r()],
            'main',
            // The trailing empty row delimiter produces no columns and is dropped.
            ['rows' => [['columns' => [['w' => 4, 'n' => 3]]], ['columns' => [['w' => 12]]]]],
        ];

        // ── Offset cases ─────────────────────────────────────────

        self::$nextId = 0;
        yield 'elements with offsets' => [
            [self::r(), self::e(8, 2), self::e(4)],
            'main',
            ['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]],
        ];

        self::$nextId = 0;
        yield 'mixed offsets across rows' => [
            [self::r(), self::e(6, 3), self::r(), self::e(4, 1), self::e(4, 1)],
            'main',
            [
                'rows' => [
                    ['columns' => [['w' => 6, 'o' => 3]]],
                    ['columns' => [['w' => 4, 'o' => 1, 'n' => 2]]],
                ],
            ],
        ];

        // ── Grouping cases ───────────────────────────────────────

        self::$nextId = 0;
        yield 'consecutive same then different then same splits correctly' => [
            [self::r(), self::e(6), self::e(6), self::e(4), self::e(6), self::e(6)],
            'main',
            ['rows' => [['columns' => [['w' => 6, 'n' => 2], ['w' => 4], ['w' => 6, 'n' => 2]]]]],
        ];

        self::$nextId = 0;
        yield 'viewport override difference prevents grouping' => [
            [
                self::r(),
                self::e(6, 0, ['MD' => 6], ['MD' => 0]),
                self::e(6, 0, ['MD' => 6], ['MD' => 0], ['SM' => 'hidden']),
                self::e(6, 0, ['MD' => 6], ['MD' => 0]),
            ],
            'main',
            ['rows' => [['columns' => [['w' => 6], ['w' => 6], ['w' => 6]]]]],
        ];

        // ── Field mapping cases ──────────────────────────────────

        self::$nextId = 0;
        yield 'first row customSectionClass applied to section' => [
            [self::r('', '', 'hero-section'), self::e(12)],
            'main',
            ['extraClass' => 'hero-section', 'rows' => [['columns' => [['w' => 12]]]]],
        ];

        self::$nextId = 0;
        yield 'row title and extraClass mapped to migration rows' => [
            [self::r('Row One', 'one-extra'), self::e(8), self::r('Row Two', 'two-extra'), self::e(4)],
            'main',
            [
                'rows' => [
                    ['title' => 'Row One', 'extraClass' => 'one-extra', 'columns' => [['w' => 8]]],
                    ['title' => 'Row Two', 'extraClass' => 'two-extra', 'columns' => [['w' => 4]]],
                ],
            ],
        ];

        self::$nextId = 0;
        yield 'implicit group produces row with default values' => [
            [self::e(12)],
            'main',
            ['rows' => [['title' => '', 'extraClass' => '', 'columns' => [['w' => 12]]]]],
        ];

        // ── Clamping case ────────────────────────────────────────

        self::$nextId = 0;
        yield 'invalid grid settings are clamped to valid range' => [
            [self::r(), self::e(15, 14)],
            'main',
            ['rows' => [['columns' => [['w' => 12, 'o' => 0]]]]],
        ];

        // ── Zone case ────────────────────────────────────────────

        self::$nextId = 0;
        yield 'zone passed through to section' => [
            [self::r(), self::e(6)],
            'sidebar',
            ['rows' => [['columns' => [['w' => 6]]]]],
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @param array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columns: list<array{w: int, o?: int, n?: int}>}>} $expectedSection
     */
    #[DataProvider('hierarchyProvider')]
    public function testHierarchy(array $elements, string $zone, array $expectedSection): void
    {
        $sections = $this->strategy->buildHierarchy($elements, pageId: 1, zone: $zone);

        self::assertCount(1, $sections, 'AllRows always produces 1 section');

        $section = $sections[0];
        self::assertSame($zone, $section->zone, 'Section: zone');
        self::assertSame(1, $section->sort, 'Section: sort');
        self::assertSame($expectedSection['extraClass'] ?? '', $section->extraClass, 'Section: extraClass');

        $expectedRows = $expectedSection['rows'];
        self::assertCount(\count($expectedRows), $section->rows, 'Row count');

        foreach ($section->rows as $ri => $row) {
            $expectedRow = $expectedRows[$ri];
            $rowPath = "Row {$ri}";

            self::assertSame($ri + 1, $row->sort, "{$rowPath}: sort");
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
