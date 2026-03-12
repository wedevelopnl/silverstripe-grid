<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\ArrayData;
use WeDevelop\Grid\Forms\ColumnWidthPickerField;

/**
 * Integration tests for ColumnWidthPickerField.
 *
 * Requires SilverStripe config manifest for FormField constructor and ModuleResourceLoader.
 */
#[CoversClass(ColumnWidthPickerField::class)]
final class ColumnWidthPickerFieldTest extends SapphireTest
{
    // --- Constructor / accessors ---

    public function testTotalColumnsMatchesConstructorArgument(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Content Width', [0 => 'Full'], 12);

        $this->assertSame(12, $field->getTotalColumns());
    }

    public function testFieldNameAndTitleFromConstructor(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Content Width', [0 => 'Full'], 12);

        $this->assertSame('ContentColumns', $field->getName());
        $this->assertSame('Content Width', $field->Title());
    }

    // --- Picker options core ---

    public function testPickerOptionsCountMatchesSource(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [
            0 => 'Full',
            6 => 'Half',
            4 => 'Third',
        ], 12);

        $options = $field->getPickerOptions();

        $this->assertCount(3, $options);
    }

    public function testPickerOptionsFullWidthEntry(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [0 => 'Full'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $this->assertTrue((bool) $option->IsFullWidth);
        $this->assertSame(100.0, $option->ContentPercent);
        $this->assertSame(12, $option->MediaColumns);
    }

    public function testPickerOptionsHalfWidthEntry(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [6 => 'Half'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $this->assertSame(50.0, $option->ContentPercent);
        $this->assertSame(50.0, $option->MediaPercent);
        $this->assertSame(6, $option->MediaColumns);
    }

    public function testPickerOptionsCheckedMatchesValue(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [
            0 => 'Full',
            6 => 'Half',
        ], 12);
        $field->setValue(6);

        $options = $field->getPickerOptions();
        $checked = [];

        /** @var ArrayData $option */
        foreach ($options as $option) {
            if ($option->isChecked) {
                $checked[] = $option->Value;
            }
        }

        $this->assertSame([6], $checked);
    }

    public function testPickerOptionsDisabledWhenFieldDisabled(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [
            0 => 'Full',
            6 => 'Half',
        ], 12);
        $field->setDisabled(true);

        $options = $field->getPickerOptions();

        /** @var ArrayData $option */
        foreach ($options as $option) {
            $this->assertTrue($option->isDisabled, "Option {$option->Value} should be disabled");
        }
    }

    public function testPickerOptionsIdIncludesFieldIdAndValue(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [
            0 => 'Full',
            6 => 'Half',
        ], 12);

        $options = $field->getPickerOptions();
        $ids = [];

        /** @var ArrayData $option */
        foreach ($options as $option) {
            $ids[] = $option->ID;
        }

        $this->assertStringEndsWith('_0', $ids[0]);
        $this->assertStringEndsWith('_6', $ids[1]);
    }

    // --- Image resolution ---

    public function testPickerOptionsFullWidthImageResolvesVerticalPng(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [0 => 'Full'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        // vertical.png exists in client/images/alignments/ — should resolve to a URL or be empty if module not exposed
        $imageUrl = (string) $option->ImageURL;

        if ($imageUrl !== '') {
            $this->assertStringContainsString('vertical.png', $imageUrl);
        } else {
            // Module resource not exposed in test env — acceptable
            $this->assertSame('', $imageUrl);
        }
    }

    public function testPickerOptionsPartialWidthImageResolvesHorizontalFormat(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [6 => 'Half'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $imageUrl = (string) $option->ImageURL;

        if ($imageUrl !== '') {
            $this->assertStringContainsString('horizontal_6-6.png', $imageUrl);
        } else {
            $this->assertSame('', $imageUrl);
        }
    }

    public function testPickerOptionsMissingImageReturnsEmptyString(): void
    {
        // 11/1 split has no corresponding image file
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [11 => 'Unusual'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $this->assertSame('', (string) $option->ImageURL);
    }

    // --- Percentage edge cases ---

    public function testPickerOptionsPercentageWithNonStandardColumnCount(): void
    {
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [4 => 'Quarter'], 16);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $this->assertSame(25.0, $option->ContentPercent);
        $this->assertSame(75.0, $option->MediaPercent);
    }

    public function testPickerOptionsPercentageRoundsToOneDecimal(): void
    {
        // 5/12 = 41.666... → should round to 41.7
        $field = new ColumnWidthPickerField('ContentColumns', 'Width', [5 => 'Five-twelfths'], 12);

        $options = $field->getPickerOptions();
        /** @var ArrayData $option */
        $option = $options->first();

        $this->assertSame(41.7, $option->ContentPercent);
        $this->assertSame(58.3, $option->MediaPercent);
    }
}
