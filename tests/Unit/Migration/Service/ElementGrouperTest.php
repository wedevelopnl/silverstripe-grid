<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;

#[CoversClass(ElementGrouper::class)]
final class ElementGrouperTest extends TestCase
{
    private ElementGrouper $grouper;

    protected function setUp(): void
    {
        $this->grouper = new ElementGrouper();
    }

    public function testEmptyListProducesNoGroups(): void
    {
        $result = $this->grouper->group([]);

        self::assertCount(0, $result);
    }

    public function testSingleElementNoRowProducesOneImplicitGroup(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);

        $result = $this->grouper->group([$e1]);

        self::assertCount(1, $result);
        self::assertNull($result[0]['rowData']);
        self::assertSame([$e1], $result[0]['elements']);
    }

    public function testNoRowsProducesOneImplicitGroupWithAllElements(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);
        $e2 = LegacyElementFactory::content(2, 2);
        $e3 = LegacyElementFactory::content(3, 3);

        $result = $this->grouper->group([$e1, $e2, $e3]);

        self::assertCount(1, $result);
        self::assertNull($result[0]['rowData']);
        self::assertSame([$e1, $e2, $e3], $result[0]['elements']);
    }

    public function testSingleRowNoElementsProducesOneEmptyGroup(): void
    {
        $row = LegacyElementFactory::row(1, 1);

        $result = $this->grouper->group([$row]);

        self::assertCount(1, $result);
        self::assertSame($row->rowData, $result[0]['rowData']);
        self::assertSame([], $result[0]['elements']);
    }

    public function testImplicitBoundaryBeforeFirstRow(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);
        $e2 = LegacyElementFactory::content(2, 2);
        $row = LegacyElementFactory::row(3, 3);
        $e3 = LegacyElementFactory::content(4, 4);

        $result = $this->grouper->group([$e1, $e2, $row, $e3]);

        self::assertCount(2, $result);

        // First group: implicit (before any row), contains e1 + e2
        self::assertNull($result[0]['rowData']);
        self::assertSame([$e1, $e2], $result[0]['elements']);

        // Second group: row's group, contains e3
        self::assertSame($row->rowData, $result[1]['rowData']);
        self::assertSame([$e3], $result[1]['elements']);
    }

    public function testElementsSplitOnExplicitRowBoundaries(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);
        $row1 = LegacyElementFactory::row(2, 2);
        $e2 = LegacyElementFactory::content(3, 3);
        $e3 = LegacyElementFactory::content(4, 4);
        $row2 = LegacyElementFactory::row(5, 5);
        $e4 = LegacyElementFactory::content(6, 6);

        $result = $this->grouper->group([$e1, $row1, $e2, $e3, $row2, $e4]);

        self::assertCount(3, $result);

        // First group: implicit (e1 before first row)
        self::assertNull($result[0]['rowData']);
        self::assertSame([$e1], $result[0]['elements']);

        // Second group: row1's group (e2, e3)
        self::assertSame($row1->rowData, $result[1]['rowData']);
        self::assertSame([$e2, $e3], $result[1]['elements']);

        // Third group: row2's group (e4)
        self::assertSame($row2->rowData, $result[2]['rowData']);
        self::assertSame([$e4], $result[2]['elements']);
    }

    public function testElementsAfterLastExplicitRow(): void
    {
        $row = LegacyElementFactory::row(1, 1);
        $e1 = LegacyElementFactory::content(2, 2);
        $e2 = LegacyElementFactory::content(3, 3);

        $result = $this->grouper->group([$row, $e1, $e2]);

        self::assertCount(1, $result);
        self::assertSame($row->rowData, $result[0]['rowData']);
        self::assertSame([$e1, $e2], $result[0]['elements']);
    }

    public function testAdjacentRowsProduceEmptyGroupForFirstRow(): void
    {
        $row1 = LegacyElementFactory::row(1, 1, new LegacyRowData(isFluid: true, customSectionClass: 'fluid-row'));
        $row2 = LegacyElementFactory::row(2, 2, new LegacyRowData(isFluid: false, customSectionClass: 'normal-row'));
        $e1 = LegacyElementFactory::content(3, 3);

        $result = $this->grouper->group([$row1, $row2, $e1]);

        self::assertCount(2, $result);

        // First row's group is empty
        self::assertSame($row1->rowData, $result[0]['rowData']);
        self::assertSame([], $result[0]['elements']);

        // Second row's group contains e1
        self::assertSame($row2->rowData, $result[1]['rowData']);
        self::assertSame([$e1], $result[1]['elements']);
    }

    public function testRowDataIsPreservedOnGroup(): void
    {
        $rowData = new LegacyRowData(isFluid: true, customSectionClass: 'my-section');
        $row = LegacyElementFactory::row(1, 1, $rowData);
        $e1 = LegacyElementFactory::content(2, 2);

        $result = $this->grouper->group([$row, $e1]);

        self::assertCount(1, $result);
        self::assertSame($rowData, $result[0]['rowData']);
    }
}
