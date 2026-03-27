<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use WeDevelop\Grid\Forms\ColumnWidthPickerField;

#[CoversClass(ColumnWidthPickerField::class)]
final class ColumnWidthPickerFieldTest extends SapphireTest
{
    protected $usesDatabase = false;

    /** @var array<int, string> */
    private const array SOURCE = [
        0 => 'Full width',
        4 => '4/8 split',
        6 => '6/6 split',
        8 => '8/4 split',
    ];

    private const int TOTAL_COLUMNS = 12;

    public function testGetTotalColumnsReturnsConstructorValue(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        self::assertSame(12, $field->getTotalColumns());
    }

    public function testGetPickerOptionsCountMatchesSource(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        self::assertCount(4, $field->getPickerOptions());
    }

    public function testGetPickerOptionsMarksSelectedValue(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);
        $field->setValue(6);

        $options = $field->getPickerOptions();
        foreach ($options as $option) {
            if ($option->Value === 6) {
                self::assertTrue($option->isChecked);
            } else {
                self::assertFalse($option->isChecked, "Option with Value={$option->Value} should not be checked");
            }
        }
    }

    public function testGetPickerOptionsFullWidthOption(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $fullWidth = $field->getPickerOptions()->find('Value', 0);
        self::assertNotNull($fullWidth);

        self::assertTrue($fullWidth->IsFullWidth);
        self::assertSame(100.0, $fullWidth->ContentPercent);
        self::assertSame(0.0, $fullWidth->MediaPercent);
        self::assertSame(12, $fullWidth->MediaColumns);
        self::assertSame(0, $fullWidth->ContentColumns);
        self::assertSame('Full width', $fullWidth->Title);
        self::assertSame('Layout', $fullWidth->Name);
    }

    public function testGetPickerOptionsCalculatesPercentages(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $option = $field->getPickerOptions()->find('Value', 6);
        self::assertNotNull($option);

        self::assertSame(50.0, $option->ContentPercent);
        self::assertSame(50.0, $option->MediaPercent);
        self::assertSame(6, $option->ContentColumns);
        self::assertSame(6, $option->MediaColumns);
        self::assertFalse($option->IsFullWidth);
        self::assertSame('Layout', $option->Name);
        self::assertSame('6/6 split', $option->Title);
    }

    public function testGetPickerOptionsEightFourSplit(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $option = $field->getPickerOptions()->find('Value', 8);
        self::assertNotNull($option);

        self::assertFalse($option->IsFullWidth);
        self::assertEqualsWithDelta(66.7, $option->ContentPercent, 0.01);
        self::assertEqualsWithDelta(33.3, $option->MediaPercent, 0.01);
        self::assertSame(8, $option->ContentColumns);
        self::assertSame(4, $option->MediaColumns);
    }

    public function testGetPickerOptionsFourEightSplitPercentages(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $option = $field->getPickerOptions()->find('Value', 4);
        self::assertNotNull($option);

        // 4/12 = 33.333... → round(..., 1) = 33.3; MediaPercent = round(100 - 33.3, 1) = 66.7
        // Exact float equality kills IncrementInteger on round() precision argument
        self::assertSame(33.3, $option->ContentPercent);
        self::assertSame(66.7, $option->MediaPercent);
        self::assertSame(4, $option->ContentColumns);
        self::assertSame(8, $option->MediaColumns);
    }
}
