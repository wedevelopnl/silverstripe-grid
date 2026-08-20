<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationColumn;
use WeDevelop\Grid\Migration\DTO\MigrationRow;
use WeDevelop\Grid\Migration\DTO\MigrationSection;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementBuilder;
use WeDevelop\Grid\Value\GridSettings;

#[CoversClass(AllRowsInSectionStrategy::class)]
#[CoversClass(MigrationSection::class)]
#[CoversClass(MigrationRow::class)]
#[CoversClass(MigrationColumn::class)]
#[CoversClass(GridSettings::class)]
final class AllRowsInSectionStrategyTest extends TestCase
{
    use AssertsSectionHierarchy;

    private AllRowsInSectionStrategy $strategy;

    private LegacyElementBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new LegacyElementBuilder();

        // Most tests never assert on logging, so the shared strategy gets a stub;
        // the warning tests build their own strategy around a mock instead.
        $this->strategy = $this->strategyLogging($this->createStub(LoggerInterface::class));
    }

    private function strategyLogging(LoggerInterface $logger): AllRowsInSectionStrategy
    {
        return new AllRowsInSectionStrategy(
            grouper: new ElementGrouper(),
            mapper: new FieldMapper(),
            defaultViewport: 'MD',
            viewportKeyMap: ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl'],
            logger: $logger,
        );
    }

    // ─── Warning behavior (needs logger mock — cannot be data provider) ──

    public function testLaterRowWithDifferentCustomSectionClassLogsWarning(): void
    {
        $row1 = $this->builder->r('', '', 'first-class');
        $row2 = $this->builder->r('', '', 'second-class');

        // Message and context asserted verbatim: the context names the discarded row,
        // which is the only way an operator can find it in the legacy data.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'AllRowsInSectionStrategy: row customSectionClass conflicts with section value; discarding row value.',
                ['rowId' => $row2->id, 'rowClass' => 'second-class', 'sectionClass' => 'first-class'],
            );

        // Each row needs a content element, or the empty row groups are dropped
        // and no section is produced.
        $sections = $this->strategyLogging($logger)->buildHierarchy(
            [$row1, $this->builder->e(6), $row2, $this->builder->e(4)],
            pageId: 10,
            zone: 'main',
        );

        self::assertSame('first-class', $sections[0]->extraClass);
    }

    public function testSectionClassIsTakenFromTheFirstRowThatCarriesRowData(): void
    {
        // The leading content element forms a group with no row (and no rowData). That
        // group must be skipped, not end the search for a section class.
        $row = $this->builder->r('', '', 'late-class');

        $sections = $this->strategy->buildHierarchy(
            [$this->builder->e(6), $row, $this->builder->e(4)],
            pageId: 10,
            zone: 'main',
        );

        self::assertSame('late-class', $sections[0]->extraClass);
    }

    public function testEmptyRowGroupIsSkippedWithoutDroppingLaterRows(): void
    {
        // The first delimiter has no content behind it, so its group yields no columns.
        // It must be skipped, leaving the second row intact.
        $empty = $this->builder->r('empty-row');
        $filled = $this->builder->r('filled-row');

        $sections = $this->strategy->buildHierarchy(
            [$empty, $filled, $this->builder->e(6)],
            pageId: 10,
            zone: 'main',
        );

        self::assertCount(1, $sections[0]->rows);
        self::assertSame('filled-row', $sections[0]->rows[0]->title);
    }

    public function testNoWarningWhenAllRowsHaveSameCustomSectionClass(): void
    {
        $row1 = $this->builder->r('', '', 'same-class');
        $row2 = $this->builder->r('', '', 'same-class');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $this->strategyLogging($logger)->buildHierarchy([$row1, $this->builder->e(6), $row2, $this->builder->e(4)], pageId: 10, zone: 'main');
    }

    public function testEmptyElementsReturnsNoSections(): void
    {
        self::assertSame([], $this->strategy->buildHierarchy([], pageId: 1, zone: 'main'));
    }

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
        $b = new LegacyElementBuilder();
        yield 'single row, three elements with same width are grouped' => [
            [$b->r(), $b->e(4), $b->e(4), $b->e(4)],
            'main',
            ['rows' => [['columns' => [['w' => 4, 'n' => 3]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'two rows, varying element counts' => [
            [$b->r(), $b->e(8), $b->e(4), $b->r(), $b->e(12)],
            'main',
            ['rows' => [['columns' => [['w' => 8], ['w' => 4]]], ['columns' => [['w' => 12]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'orphans before first row' => [
            [$b->e(8), $b->e(4), $b->r(), $b->e(12)],
            'main',
            ['rows' => [['columns' => [['w' => 8], ['w' => 4]]], ['columns' => [['w' => 12]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'orphans only with same width are grouped' => [
            [$b->e(6), $b->e(6)],
            'main',
            ['rows' => [['columns' => [['w' => 6, 'n' => 2]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'three rows: 3 same elements grouped, 1 element, trailing empty row dropped' => [
            [$b->r(), $b->e(4), $b->e(4), $b->e(4), $b->r(), $b->e(12), $b->r()],
            'main',
            // The trailing empty row delimiter produces no columns and is dropped.
            ['rows' => [['columns' => [['w' => 4, 'n' => 3]]], ['columns' => [['w' => 12]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'elements with offsets' => [
            [$b->r(), $b->e(8, 2), $b->e(4)],
            'main',
            ['rows' => [['columns' => [['w' => 8, 'o' => 2], ['w' => 4]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'mixed offsets across rows' => [
            [$b->r(), $b->e(6, 3), $b->r(), $b->e(4, 1), $b->e(4, 1)],
            'main',
            [
                'rows' => [
                    ['columns' => [['w' => 6, 'o' => 3]]],
                    ['columns' => [['w' => 4, 'o' => 1, 'n' => 2]]],
                ],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'consecutive same then different then same splits correctly' => [
            [$b->r(), $b->e(6), $b->e(6), $b->e(4), $b->e(6), $b->e(6)],
            'main',
            ['rows' => [['columns' => [['w' => 6, 'n' => 2], ['w' => 4], ['w' => 6, 'n' => 2]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'viewport override difference prevents grouping' => [
            [
                $b->r(),
                $b->e(6, 0, ['MD' => 6], ['MD' => 0]),
                $b->e(6, 0, ['MD' => 6], ['MD' => 0], ['SM' => 'hidden']),
                $b->e(6, 0, ['MD' => 6], ['MD' => 0]),
            ],
            'main',
            ['rows' => [['columns' => [['w' => 6], ['w' => 6], ['w' => 6]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'first row customSectionClass applied to section' => [
            [$b->r('', '', 'hero-section'), $b->e(12)],
            'main',
            ['extraClass' => 'hero-section', 'rows' => [['columns' => [['w' => 12]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'row title and extraClass mapped to migration rows' => [
            [$b->r('Row One', 'one-extra'), $b->e(8), $b->r('Row Two', 'two-extra'), $b->e(4)],
            'main',
            [
                'rows' => [
                    ['title' => 'Row One', 'extraClass' => 'one-extra', 'columns' => [['w' => 8]]],
                    ['title' => 'Row Two', 'extraClass' => 'two-extra', 'columns' => [['w' => 4]]],
                ],
            ],
        ];

        $b = new LegacyElementBuilder();
        yield 'implicit group produces row with default values' => [
            [$b->e(12)],
            'main',
            ['rows' => [['title' => '', 'extraClass' => '', 'columns' => [['w' => 12]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'invalid grid settings are clamped to valid range' => [
            [$b->r(), $b->e(15, 14)],
            'main',
            ['rows' => [['columns' => [['w' => 12, 'o' => 0]]]]],
        ];

        $b = new LegacyElementBuilder();
        yield 'zone passed through to section' => [
            [$b->r(), $b->e(6)],
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

        // AllRows always produces exactly 1 section; rows sort sequentially within it.
        self::assertSectionsMatchSpec($sections, $zone, [$expectedSection], rowSortFollowsIndex: true);
    }
}
