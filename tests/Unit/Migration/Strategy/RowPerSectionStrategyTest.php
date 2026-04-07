<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;

#[CoversClass(RowPerSectionStrategy::class)]
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

    public function testEachExplicitRowGroupProducesOneSectionWithOneRow(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $e1 = LegacyElementFactory::content(2, 2);
        $row2 = LegacyElementFactory::row(3, 3);
        $e2 = LegacyElementFactory::content(4, 4);

        $sections = $this->strategy->buildHierarchy([$row1, $e1, $row2, $e2], pageId: 10, zone: 'main');

        self::assertCount(2, $sections);
        self::assertCount(1, $sections[0]->rows);
        self::assertCount(1, $sections[1]->rows);
    }

    public function testRowFieldsMapToSectionAndRow(): void
    {
        $rowData = new LegacyRowData(isFluid: true, customSectionClass: 'my-section-class');
        $row = LegacyElementFactory::row(1, 1, $rowData);
        $row = new \WeDevelop\Grid\Migration\DTO\LegacyElement(
            id: 1,
            className: 'Test\Row',
            title: 'My Row Title',
            showTitle: false,
            titleTag: 'h2',
            titleClass: '',
            sort: 1,
            extraClass: 'my-row-extra',
            isRow: true,
            sizeFields: [],
            offsetFields: [],
            visibilityFields: [],
            rowData: $rowData,
        );

        $sections = $this->strategy->buildHierarchy([$row], pageId: 10, zone: 'main');

        self::assertCount(1, $sections);
        $section = $sections[0];
        self::assertTrue($section->isFluid);
        self::assertSame('my-section-class', $section->extraClass);

        self::assertCount(1, $section->rows);
        $migrationRow = $section->rows[0];
        self::assertSame('My Row Title', $migrationRow->title);
        self::assertSame('my-row-extra', $migrationRow->extraClass);
    }

    public function testContentElementsBecomeMigrationColumnsWithCorrectSort(): void
    {
        $row = LegacyElementFactory::row(1, 1);
        $e1 = LegacyElementFactory::content(2, 2, ['sizeFields' => ['MD' => 6]]);
        $e2 = LegacyElementFactory::content(3, 3, ['sizeFields' => ['MD' => 4]]);

        $sections = $this->strategy->buildHierarchy([$row, $e1, $e2], pageId: 10, zone: 'main');

        $columns = $sections[0]->rows[0]->columns;
        self::assertCount(2, $columns);
        self::assertSame(1, $columns[0]->sort);
        self::assertSame(2, $columns[1]->sort);
        self::assertSame(6, $columns[0]->gridSettings->default->width);
        self::assertSame(4, $columns[1]->gridSettings->default->width);
    }

    public function testImplicitGroupBeforeFirstRowProducesSectionWithDefaultValues(): void
    {
        $e1 = LegacyElementFactory::content(1, 1);
        $e2 = LegacyElementFactory::content(2, 2);
        $row = LegacyElementFactory::row(3, 3);
        $e3 = LegacyElementFactory::content(4, 4);

        $sections = $this->strategy->buildHierarchy([$e1, $e2, $row, $e3], pageId: 10, zone: 'main');

        self::assertCount(2, $sections);

        // Implicit group — defaults
        $implicitSection = $sections[0];
        self::assertFalse($implicitSection->isFluid);
        self::assertSame('', $implicitSection->extraClass);
        self::assertSame('', $implicitSection->title);

        $implicitRow = $implicitSection->rows[0];
        self::assertSame('', $implicitRow->title);
        self::assertSame('', $implicitRow->extraClass);
    }

    public function testEmptyRowGroupProducesSectionWithRowAndNoColumns(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        self::assertCount(2, $sections);
        self::assertCount(1, $sections[0]->rows);
        self::assertSame([], $sections[0]->rows[0]->columns);
    }

    public function testZoneIsPassedThroughToAllSections(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'sidebar');

        self::assertSame('sidebar', $sections[0]->zone);
        self::assertSame('sidebar', $sections[1]->zone);
    }

    public function testSectionSortValuesAreSequential(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);
        $row3 = LegacyElementFactory::row(3, 3);

        $sections = $this->strategy->buildHierarchy([$row1, $row2, $row3], pageId: 10, zone: 'main');

        self::assertSame(1, $sections[0]->sort);
        self::assertSame(2, $sections[1]->sort);
        self::assertSame(3, $sections[2]->sort);
    }

    public function testRowSortIsAlwaysOne(): void
    {
        $row1 = LegacyElementFactory::row(1, 1);
        $row2 = LegacyElementFactory::row(2, 2);

        $sections = $this->strategy->buildHierarchy([$row1, $row2], pageId: 10, zone: 'main');

        self::assertSame(1, $sections[0]->rows[0]->sort);
        self::assertSame(1, $sections[1]->rows[0]->sort);
    }
}
