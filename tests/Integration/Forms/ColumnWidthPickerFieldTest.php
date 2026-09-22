<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Forms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A split whose diagram PNG exists on disk (content 8 → media 4 →
     * horizontal_4-8.png) must resolve to a non-empty public URL, while a
     * split whose PNG is absent (content 1 → media 11 → horizontal_11-1.png
     * does not ship) must fall back to an empty string. Both arms of the
     * resource-existence guard are exercised in one assertion pair.
     */
    public function testImageUrlResolvesForPresentPngAndEmptyForMissingPng(): void
    {
        $field = new ColumnWidthPickerField(
            'Layout',
            'Layout',
            [8 => '8/4 split', 1 => '1/11 split'],
            self::TOTAL_COLUMNS,
        );

        $options = $field->getPickerOptions();

        $present = $options->find('Value', 8);
        self::assertNotNull($present);
        self::assertNotSame('', $present->ImageURL, 'Present PNG (content 8) must resolve to a non-empty URL');

        $missing = $options->find('Value', 1);
        self::assertNotNull($missing);
        self::assertSame('', $missing->ImageURL, 'Missing PNG (content 1) must fall back to an empty URL');
    }

    /**
     * The diagram filenames are media-first, because that is the order each
     * one draws its two columns in; every other layer counts content first.
     * A content-first filename resolves the mirror image of the split the
     * editor picked, which is the defect this pins (WDVLP-296).
     *
     * @return iterable<string, array{int, string}>
     */
    public static function splitDiagramProvider(): iterable
    {
        yield 'content 4 shows the wide media block' => [4, 'horizontal_8-4.png'];
        yield 'content 5 shows a 7-column media block' => [5, 'horizontal_7-5.png'];
        yield 'content 6 shows an even split' => [6, 'horizontal_6-6.png'];
        yield 'content 7 shows a 5-column media block' => [7, 'horizontal_5-7.png'];
        yield 'content 8 shows the narrow media block' => [8, 'horizontal_4-8.png'];
        yield 'full width shows the stacked diagram' => [0, 'vertical.png'];
    }

    #[DataProvider('splitDiagramProvider')]
    public function testSplitResolvesTheDiagramDrawnForIt(int $contentColumns, string $expectedFilename): void
    {
        $field = new ColumnWidthPickerField(
            'Layout',
            'Layout',
            [$contentColumns => 'split'],
            self::TOTAL_COLUMNS,
        );

        $option = $field->getPickerOptions()->find('Value', $contentColumns);
        self::assertNotNull($option);

        /** @var string $imageUrl */
        $imageUrl = $option->ImageURL;

        // The URL carries a ?m=<mtime> cache-buster; only the filename is ours.
        self::assertSame($expectedFilename, basename((string) parse_url($imageUrl, PHP_URL_PATH)));
    }

    /**
     * No PNG ships for every conceivable split, and the CSS fallback must draw
     * its two bars media-first like every PNG does — otherwise the picker
     * mirrors the split for exactly the splits that have no image to check it
     * against.
     */
    public function testFallbackDiagramDrawsTheMediaBarBeforeTheContentBar(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', [1 => '11/1 split'], self::TOTAL_COLUMNS);

        $html = (string) $field->Field();

        $mediaAt = strpos($html, 'ssgrid-column-width-picker-bar-media');
        $contentAt = strpos($html, 'ssgrid-column-width-picker-bar-content');

        self::assertIsInt($mediaAt, 'The fallback diagram must render a media bar');
        self::assertIsInt($contentAt, 'The fallback diagram must render a content bar');
        self::assertLessThan($contentAt, $mediaAt);
    }

    /**
     * The label renders the option's own title. Composing it from the raw
     * value instead dropped the "(media/content)" hint the title carries,
     * leaving a bare ratio that reads as content-first (WDVLP-296).
     */
    public function testLabelRendersTheOptionTitle(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', [4 => '8/4 (media/content)'], self::TOTAL_COLUMNS);

        $html = (string) $field->Field();

        self::assertMatchesRegularExpression(
            '#<span class="ssgrid-column-width-picker-label">\s*8/4 \(media/content\)\s*</span>#',
            $html,
        );
    }

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

        self::assertSame(100.0, $fullWidth->ContentPercent);
        self::assertSame(0.0, $fullWidth->MediaPercent);
        self::assertSame(12, $fullWidth->MediaColumns);
        self::assertSame('Full width', $fullWidth->Title);
    }

    public function testGetPickerOptionsCalculatesPercentages(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $option = $field->getPickerOptions()->find('Value', 6);
        self::assertNotNull($option);

        self::assertSame(50.0, $option->ContentPercent);
        self::assertSame(50.0, $option->MediaPercent);
        self::assertSame(6, $option->MediaColumns);
        self::assertSame('6/6 split', $option->Title);
    }

    public function testGetPickerOptionsEightFourSplit(): void
    {
        $field = new ColumnWidthPickerField('Layout', 'Layout', self::SOURCE, self::TOTAL_COLUMNS);

        $option = $field->getPickerOptions()->find('Value', 8);
        self::assertNotNull($option);

        self::assertEqualsWithDelta(66.7, $option->ContentPercent, 0.01);
        self::assertEqualsWithDelta(33.3, $option->MediaPercent, 0.01);
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
        self::assertSame(8, $option->MediaColumns);
    }
}
