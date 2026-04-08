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

#[CoversClass(AllRowsInSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
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

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        self::assertSame('first-class', $sections[0]->extraClass);
    }

    public function testNoWarningWhenAllRowsHaveSameCustomSectionClass(): void
    {
        $row1 = self::r('', '', 'same-class');
        $row2 = self::r('', '', 'same-class');

        $this->logger->expects(self::never())->method('warning');

        $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');
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
     * Each case yields: [elements, zone, expected section spec].
     *
     * AllRows always produces exactly 1 section. The expected spec is:
     *   ['extraClass' => '', 'rows' => [['title' => '', 'extraClass' => '', 'columnWidths' => [8, 4]]]]
     *
     * @return iterable<string, array{list<LegacyElement>, string, array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columnWidths: list<int>}>}}>
     */
    public static function hierarchyProvider(): iterable
    {
        // ── Structural cases ─────────────────────────────────────

        self::$nextId = 0;
        yield 'single row, three elements' => [
            [self::r(), self::e(4), self::e(4), self::e(4)],
            'main',
            ['rows' => [['columnWidths' => [4, 4, 4]]]],
        ];

        self::$nextId = 0;
        yield 'two rows, varying element counts' => [
            [self::r(), self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            ['rows' => [['columnWidths' => [8, 4]], ['columnWidths' => [12]]]],
        ];

        self::$nextId = 0;
        yield 'orphans before first row' => [
            [self::e(8), self::e(4), self::r(), self::e(12)],
            'main',
            ['rows' => [['columnWidths' => [8, 4]], ['columnWidths' => [12]]]],
        ];

        self::$nextId = 0;
        yield 'orphans only, no rows' => [
            [self::e(6), self::e(6)],
            'main',
            ['rows' => [['columnWidths' => [6, 6]]]],
        ];

        self::$nextId = 0;
        yield 'three rows: 3 elements, 1 element, empty' => [
            [self::r(), self::e(4), self::e(4), self::e(4), self::r(), self::e(12), self::r()],
            'main',
            ['rows' => [['columnWidths' => [4, 4, 4]], ['columnWidths' => [12]], ['columnWidths' => []]]],
        ];

        // ── Field mapping cases ──────────────────────────────────

        self::$nextId = 0;
        yield 'first row customSectionClass applied to section' => [
            [self::r('', '', 'hero-section'), self::e(12)],
            'main',
            ['extraClass' => 'hero-section', 'rows' => [['columnWidths' => [12]]]],
        ];

        self::$nextId = 0;
        yield 'row title and extraClass mapped to migration rows' => [
            [self::r('Row One', 'one-extra'), self::e(8), self::r('Row Two', 'two-extra'), self::e(4)],
            'main',
            [
                'rows' => [
                    ['title' => 'Row One', 'extraClass' => 'one-extra', 'columnWidths' => [8]],
                    ['title' => 'Row Two', 'extraClass' => 'two-extra', 'columnWidths' => [4]],
                ],
            ],
        ];

        self::$nextId = 0;
        yield 'implicit group produces row with default values' => [
            [self::e(12)],
            'main',
            ['rows' => [['title' => '', 'extraClass' => '', 'columnWidths' => [12]]]],
        ];

        // ── Zone case ────────────────────────────────────────────

        self::$nextId = 0;
        yield 'zone passed through to section' => [
            [self::r()],
            'sidebar',
            ['rows' => [['columnWidths' => []]]],
        ];
    }

    /**
     * @param list<LegacyElement> $elements
     * @param array{extraClass?: string, rows: list<array{title?: string, extraClass?: string, columnWidths: list<int>}>} $expectedSection
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
