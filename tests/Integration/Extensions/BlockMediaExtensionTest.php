<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Integration tests for BlockMediaExtension applied to ContentElement.
 *
 * Tests use the real BootstrapAdapter (default DI binding) to verify
 * CSS class output against actual framework values. No adapter mocks.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaExtensionTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);
    }

    // --- Extension wiring ---

    public function testExtensionIsAppliedToContentElement(): void
    {
        $element = ContentElement::create();

        $this->assertTrue($element->hasExtension(BlockMediaExtension::class));
    }

    public function testDefaultsAreApplied(): void
    {
        $element = ContentElement::create();
        $element->write();

        $this->assertSame('first', $element->MediaPosition);
        $this->assertSame('center', $element->VerticalAlignment);
        $this->assertSame('auto', $element->MediaRatio);
    }

    // --- CMS fields ---

    public function testCMSFieldsIncludeMediaAndLayoutTabs(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->findTab('Root.Media'), 'Media tab should exist');
        $this->assertNotNull($fields->findTab('Root.Layout'), 'Layout tab should exist');
    }

    public function testCMSFieldsIncludeLayoutControls(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->dataFieldByName('ContentColumns'));
        $this->assertNotNull($fields->dataFieldByName('MediaPosition'));
        $this->assertNotNull($fields->dataFieldByName('VerticalAlignment'));
        $this->assertNotNull($fields->dataFieldByName('GapSize'));
    }

    public function testVideoEmbedTabNotShownWithoutEmbedData(): void
    {
        $element = ContentElement::create();
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNull($fields->findTab('Root.VideoEmbed'));
    }

    public function testVideoEmbedTabShownWithEmbedData(): void
    {
        $element = ContentElement::create();
        $element->VideoEmbedName = 'Test Video';
        $element->write();

        $fields = $element->getCMSFields();

        $this->assertNotNull($fields->findTab('Root.VideoEmbed'));
    }

    // --- Write cycle ---

    public function testWritePersistsMediaFields(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://example.com/video';
        $element->ContentColumns = 8;
        $element->MediaPosition = 'last-on-desktop';
        $element->VerticalAlignment = 'top';
        $element->GapSize = 3;
        $element->MediaRatio = '16x9';
        $element->write();

        $reloaded = ContentElement::get()->byID($element->ID);
        $this->assertNotNull($reloaded);

        $this->assertSame('video', $reloaded->MediaType);
        $this->assertSame('https://example.com/video', $reloaded->VideoURL);
        $this->assertSame(8, (int) $reloaded->ContentColumns);
        $this->assertSame('last-on-desktop', $reloaded->MediaPosition);
        $this->assertSame('top', $reloaded->VerticalAlignment);
        $this->assertSame(3, (int) $reloaded->GapSize);
        $this->assertSame('16x9', $reloaded->MediaRatio);
    }

    public function testOnBeforeWriteTrimsVideoURL(): void
    {
        $element = ContentElement::create();
        $element->VideoURL = '  https://example.com/video  ';
        $element->write();

        $this->assertSame('https://example.com/video', $element->VideoURL);
    }

    // --- Enum parsing ---

    public function testMediaPositionEnumDefaultsToFirst(): void
    {
        $element = $this->createWrittenElement();

        $this->assertSame(MediaPosition::First, $element->getMediaPositionEnum());
    }

    public function testVerticalAlignmentEnumDefaultsToCenter(): void
    {
        $element = $this->createWrittenElement();

        $this->assertSame(VerticalAlignment::Center, $element->getVerticalAlignmentEnum());
    }

    public function testAspectRatioEnumDefaultsToAuto(): void
    {
        $element = $this->createWrittenElement();

        $this->assertSame(AspectRatio::Auto, $element->getAspectRatioEnum());
    }

    #[DataProvider('mediaPositionProvider')]
    public function testMediaPositionEnumParsesCorrectly(string $value, MediaPosition $expected): void
    {
        $element = $this->createWrittenElement(['MediaPosition' => $value]);

        $this->assertSame($expected, $element->getMediaPositionEnum());
    }

    /**
     * @return iterable<string, array{string, MediaPosition}>
     */
    public static function mediaPositionProvider(): iterable
    {
        yield 'first' => ['first', MediaPosition::First];
        yield 'last' => ['last', MediaPosition::Last];
        yield 'last-on-desktop' => ['last-on-desktop', MediaPosition::LastOnDesktop];
    }

    // --- hasMedia ---

    public function testHasMediaReturnsFalseWithNoMediaType(): void
    {
        $element = $this->createWrittenElement();

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForImageWithoutAttachment(): void
    {
        $element = $this->createWrittenElement(['MediaType' => 'image']);

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForVideoWithoutURL(): void
    {
        $element = $this->createWrittenElement(['MediaType' => 'video']);

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsTrueForVideoWithURL(): void
    {
        $element = $this->createWrittenElement([
            'MediaType' => 'video',
            'VideoURL' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->assertTrue($element->hasMedia());
    }

    // --- isLayoutMode ---

    public function testIsLayoutModeReturnsFalseWithoutContentColumns(): void
    {
        $element = $this->createWrittenElement([
            'MediaType' => 'video',
            'VideoURL' => 'https://example.com/video',
        ]);

        $this->assertFalse($element->isLayoutMode());
    }

    public function testIsLayoutModeReturnsFalseWithoutMedia(): void
    {
        $element = $this->createWrittenElement(['ContentColumns' => 8]);

        $this->assertFalse($element->isLayoutMode());
    }

    public function testIsLayoutModeReturnsTrueWithMediaAndColumns(): void
    {
        $element = $this->createWrittenElement([
            'MediaType' => 'video',
            'VideoURL' => 'https://example.com/video',
            'ContentColumns' => 8,
        ]);

        $this->assertTrue($element->isLayoutMode());
    }

    // --- Layout classes (verified against Bootstrap 5 adapter) ---

    public function testLayoutRowClassesCombinesRowAndAlignment(): void
    {
        $element = $this->createWrittenElement(['VerticalAlignment' => 'center']);

        $this->assertSame('row align-items-center', $element->getLayoutRowClasses());
    }

    public function testLayoutRowClassesWithTopAlignment(): void
    {
        $element = $this->createWrittenElement(['VerticalAlignment' => 'top']);

        $this->assertSame('row align-items-start', $element->getLayoutRowClasses());
    }

    public function testLayoutRowClassesWithBottomAlignment(): void
    {
        $element = $this->createWrittenElement(['VerticalAlignment' => 'bottom']);

        $this->assertSame('row align-items-end', $element->getLayoutRowClasses());
    }

    public function testMediaColumnClassesForFirstPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'first',
        ]);

        $this->assertSame('col-md-4 order-1', $element->getMediaColumnClasses());
    }

    public function testMediaColumnClassesForLastPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'last',
        ]);

        $this->assertSame('col-md-4 order-2', $element->getMediaColumnClasses());
    }

    public function testMediaColumnClassesForLastOnDesktopPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'last-on-desktop',
        ]);

        $this->assertSame('col-md-4 order-1 order-md-2', $element->getMediaColumnClasses());
    }

    public function testContentColumnClassesForFirstPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'first',
        ]);

        $this->assertSame('col-md-8 order-2', $element->getContentColumnClasses());
    }

    public function testContentColumnClassesForLastPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'last',
        ]);

        $this->assertSame('col-md-8 order-1', $element->getContentColumnClasses());
    }

    public function testContentColumnClassesForLastOnDesktopPosition(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaPosition' => 'last-on-desktop',
        ]);

        $this->assertSame('col-md-8 order-2 order-md-1', $element->getContentColumnClasses());
    }

    // --- Padding classes ---

    public function testContentPaddingClassesWithGapAndFirstPosition(): void
    {
        $element = $this->createWrittenElement([
            'GapSize' => 3,
            'MediaPosition' => 'first',
        ]);

        $this->assertSame('ps-md-3', $element->getContentPaddingClasses());
    }

    public function testContentPaddingClassesWithGapAndLastPosition(): void
    {
        $element = $this->createWrittenElement([
            'GapSize' => 3,
            'MediaPosition' => 'last',
        ]);

        $this->assertSame('pe-md-3', $element->getContentPaddingClasses());
    }

    public function testContentPaddingClassesWithGapAndLastOnDesktopPosition(): void
    {
        $element = $this->createWrittenElement([
            'GapSize' => 5,
            'MediaPosition' => 'last-on-desktop',
        ]);

        $this->assertSame('pe-md-5', $element->getContentPaddingClasses());
    }

    public function testContentPaddingClassesWithZeroGap(): void
    {
        $element = $this->createWrittenElement(['GapSize' => 0]);

        $this->assertSame('', $element->getContentPaddingClasses());
    }

    // --- Aspect ratio ---

    public function testMediaRatioClassReturnsNullForAuto(): void
    {
        $element = $this->createWrittenElement(['MediaRatio' => 'auto']);

        $this->assertNull($element->getMediaRatioClass());
    }

    #[DataProvider('aspectRatioClassProvider')]
    public function testMediaRatioClassReturnsBootstrapClass(string $ratio, string $expected): void
    {
        $element = $this->createWrittenElement(['MediaRatio' => $ratio]);

        $this->assertSame($expected, $element->getMediaRatioClass());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function aspectRatioClassProvider(): iterable
    {
        yield 'square' => ['1x1', 'ratio ratio-1x1'];
        yield '4:3' => ['4x3', 'ratio ratio-4x3'];
        yield '16:9' => ['16x9', 'ratio ratio-16x9'];
    }

    // --- Image sizing (Bootstrap: 12 columns, 1320px container) ---

    #[DataProvider('mediaImageWidthProvider')]
    public function testMediaImageWidth(int $contentColumns, int $expectedWidth): void
    {
        $element = $this->createWrittenElement(['ContentColumns' => $contentColumns]);

        $this->assertSame($expectedWidth, $element->getMediaImageWidth());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function mediaImageWidthProvider(): iterable
    {
        // colSize = 12 - contentColumns (or 12 when contentColumns = 0)
        // width = round(1320 * colSize / 12)
        yield '8 content → 4 media → 440px' => [8, 440];
        yield '4 content → 8 media → 880px' => [4, 880];
        yield '0 content → 12 full → 1320px' => [0, 1320];
        yield '6 content → 6 media → 660px' => [6, 660];
    }

    public function testMediaImageHeightForSquareRatio(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaRatio' => '1x1',
        ]);

        $this->assertSame(440, $element->getMediaImageHeight());
    }

    public function testMediaImageHeightForSixteenByNine(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaRatio' => '16x9',
        ]);

        // 440 * 9 / 16 = 247.5 → 248
        $this->assertSame(248, $element->getMediaImageHeight());
    }

    public function testMediaImageHeightForFourByThree(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaRatio' => '4x3',
        ]);

        // 440 * 3 / 4 = 330
        $this->assertSame(330, $element->getMediaImageHeight());
    }

    public function testMediaImageHeightFallsBackToWidthWithoutImageForAutoRatio(): void
    {
        $element = $this->createWrittenElement([
            'ContentColumns' => 8,
            'MediaRatio' => 'auto',
        ]);

        // No image attached → falls back to width
        $this->assertSame(440, $element->getMediaImageHeight());
    }

    public function testMediaImageSourceURLReturnsNullWithoutImage(): void
    {
        $element = $this->createWrittenElement();

        $this->assertNull($element->getMediaImageSourceURL());
    }

    // --- Helpers ---

    /**
     * @param array<string, mixed> $fields
     */
    private function createWrittenElement(array $fields = []): ContentElement
    {
        $element = ContentElement::create();

        foreach ($fields as $name => $value) {
            $element->$name = $value;
        }

        $element->write();

        return $element;
    }
}
