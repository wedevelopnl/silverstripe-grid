<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\AllRowsInSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;

#[CoversClass(AllRowsInSectionStrategy::class)]
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

    public function testAllGroupsBecomeRowsUnderASingleSection(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $e1 = LegacyElementFactory::content(2, 2);
        $row2 = LegacyElementFactory::row(3, 3);
        $e2 = LegacyElementFactory::content(4, 4);

        $sections = $this->strategy->buildHierarchy([$row1, $e1, $row2, $e2], pageId: 10, zone: 'main');

        self::assertCount(1, $sections);
        self::assertCount(2, $sections[0]->rows);
    }

    public function testFirstRowCustomClassAppliedToSection(): void
    {
        $rowData = new LegacyRowData(customSectionClass: 'hero-section');
        $row = LegacyElementFactory::row(1, 1, $rowData);

        $sections = $this->strategy->buildHierarchy([$row], pageId: 10, zone: 'main');

        self::assertSame('hero-section', $sections[0]->extraClass);
    }

    public function testLaterRowWithDifferentCustomSectionClassLogsWarningAndIsDiscarded(): void
    {
        $row1 = LegacyElementFactory::row(1, 1, new LegacyRowData(customSectionClass: 'first-class'));
        $row2 = LegacyElementFactory::row(2, 2, new LegacyRowData(customSectionClass: 'second-class'));

        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::stringContains('customSectionClass'));

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        // Section keeps the first row's value
        self::assertSame('first-class', $sections[0]->extraClass);
    }

    public function testEachRowTitleAndExtraClassMappedToMigrationRow(): void
    {
        $row1 = new LegacyElement(
            id: 1,
            className: 'Test\Row',
            title: 'Row One',
            showTitle: false,
            titleTag: 'h2',
            titleClass: '',
            sort: 1,
            extraClass: 'row-one-extra',
            isRow: true,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
            rowData: new LegacyRowData(customSectionClass: ''),
        );
        $row2 = new LegacyElement(
            id: 2,
            className: 'Test\Row',
            title: 'Row Two',
            showTitle: false,
            titleTag: 'h2',
            titleClass: '',
            sort: 2,
            extraClass: 'row-two-extra',
            isRow: true,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
            rowData: new LegacyRowData(customSectionClass: ''),
        );

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        $rows = $sections[0]->rows;
        self::assertSame('Row One', $rows[0]->title);
        self::assertSame('row-one-extra', $rows[0]->extraClass);
        self::assertSame('Row Two', $rows[1]->title);
        self::assertSame('row-two-extra', $rows[1]->extraClass);
    }

    public function testImplicitGroupProducesRowWithDefaultValues(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);

        $sections = $this->strategy->buildHierarchy([$e1], pageId: 10, zone: 'main');

        self::assertCount(1, $sections);
        $row = $sections[0]->rows[0];
        self::assertSame('', $row->title);
        self::assertSame('', $row->extraClass);
    }

    public function testSectionSortIsAlwaysOne(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        self::assertSame(1, $sections[0]->sort);
    }

    public function testRowSortsAreSequential(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);
        $row3 = LegacyElementFactory::row(3, 3);

        $sections = $this->strategy->buildHierarchy([$row1, $row2, $row3], pageId: 10, zone: 'main');

        $rows = $sections[0]->rows;
        self::assertSame(1, $rows[0]->sort);
        self::assertSame(2, $rows[1]->sort);
        self::assertSame(3, $rows[2]->sort);
    }

    public function testZoneIsPassedThroughToSection(): void
    {
        $row = LegacyElementFactory::row(1, 1);

        $sections = $this->strategy->buildHierarchy([$row], pageId: 10, zone: 'sidebar');

        self::assertSame('sidebar', $sections[0]->zone);
    }

    public function testNoWarningWhenAllRowsHaveSameCustomSectionClass(): void
    {
        $row1 = LegacyElementFactory::row(1, 1, new LegacyRowData(customSectionClass: 'same-class'));
        $row2 = LegacyElementFactory::row(2, 2, new LegacyRowData(customSectionClass: 'same-class'));

        $this->logger->expects(self::never())->method('warning');

        $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');
    }
}
