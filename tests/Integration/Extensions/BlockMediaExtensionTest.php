<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Image;
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
 * Uses the default Bootstrap adapter (wired via Injector in the test environment).
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

    // --- hasMedia ---

    public function testHasMediaReturnsFalseWithNoMediaType(): void
    {
        $element = ContentElement::create();
        $element->write();

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForImageWithoutAttachment(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'image';
        $element->write();

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForVideoWithoutURL(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->write();

        $this->assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsTrueForVideoWithURL(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $element->write();

        $this->assertTrue($element->hasMedia());
    }

    // --- isLayoutMode ---

    public function testIsLayoutModeReturnsFalseWithoutContentColumns(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://example.com/video';
        $element->write();

        $this->assertFalse($element->isLayoutMode());
    }

    public function testIsLayoutModeReturnsFalseWithoutMedia(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->write();

        $this->assertFalse($element->isLayoutMode());
    }

    public function testIsLayoutModeReturnsTrueWithMediaAndColumns(): void
    {
        $element = ContentElement::create();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://example.com/video';
        $element->ContentColumns = 8;
        $element->write();

        $this->assertTrue($element->isLayoutMode());
    }

    // --- Enum parsing with defaults ---

    public function testMediaPositionEnumDefaultsToFirst(): void
    {
        $element = ContentElement::create();

        $this->assertSame(MediaPosition::First, $element->getMediaPositionEnum());
    }

    public function testVerticalAlignmentEnumDefaultsToCenter(): void
    {
        $element = ContentElement::create();

        $this->assertSame(VerticalAlignment::Center, $element->getVerticalAlignmentEnum());
    }

    public function testAspectRatioEnumDefaultsToAuto(): void
    {
        $element = ContentElement::create();

        $this->assertSame(AspectRatio::Auto, $element->getAspectRatioEnum());
    }

    #[DataProvider('mediaPositionProvider')]
    public function testMediaPositionEnumParsesCorrectly(string $value, MediaPosition $expected): void
    {
        $element = ContentElement::create();
        $element->MediaPosition = $value;

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

    // --- Layout class generation (Bootstrap adapter) ---

    public function testLayoutRowClassesIncludeRowAndAlignment(): void
    {
        $element = ContentElement::create();
        $element->VerticalAlignment = 'center';
        $element->write();

        $classes = $element->getLayoutRowClasses();

        $this->assertStringContainsString('row', $classes);
        $this->assertStringContainsString('align-items-center', $classes);
    }

    public function testMediaColumnClassesForBootstrap(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->MediaPosition = 'first';
        $element->write();

        $classes = $element->getMediaColumnClasses();

        // Bootstrap: col-md-4 (12 - 8 = 4 media columns), order-1
        $this->assertStringContainsString('col-md-4', $classes);
        $this->assertStringContainsString('order-1', $classes);
    }

    public function testContentColumnClassesForBootstrap(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->MediaPosition = 'first';
        $element->write();

        $classes = $element->getContentColumnClasses();

        // Bootstrap: col-md-8, order-2
        $this->assertStringContainsString('col-md-8', $classes);
        $this->assertStringContainsString('order-2', $classes);
    }

    public function testContentPaddingClassesWithGap(): void
    {
        $element = ContentElement::create();
        $element->GapSize = 3;
        $element->MediaPosition = 'first';
        $element->write();

        // Media first → content padding on left
        $this->assertSame('ps-md-3', $element->getContentPaddingClasses());
    }

    public function testContentPaddingClassesWithZeroGap(): void
    {
        $element = ContentElement::create();
        $element->GapSize = 0;
        $element->write();

        $this->assertSame('', $element->getContentPaddingClasses());
    }

    public function testContentPaddingDirectionForLastPosition(): void
    {
        $element = ContentElement::create();
        $element->GapSize = 3;
        $element->MediaPosition = 'last';
        $element->write();

        // Media last → content padding on right
        $this->assertSame('pe-md-3', $element->getContentPaddingClasses());
    }

    // --- Aspect ratio ---

    public function testMediaRatioClassReturnsNullForAuto(): void
    {
        $element = ContentElement::create();
        $element->MediaRatio = 'auto';

        $this->assertNull($element->getMediaRatioClass());
    }

    public function testMediaRatioClassForSixteenByNine(): void
    {
        $element = ContentElement::create();
        $element->MediaRatio = '16x9';

        $this->assertSame('ratio ratio-16x9', $element->getMediaRatioClass());
    }

    // --- Image sizing ---

    public function testMediaImageWidthForSmallMediaColumn(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->write();

        // colSize = 12 - 8 = 4 → <= 6 → 720px
        $this->assertSame(720, $element->getMediaImageWidth());
    }

    public function testMediaImageWidthForMediumMediaColumn(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 4;
        $element->write();

        // colSize = 12 - 4 = 8 → > 6, <= 10 → 1200px
        $this->assertSame(1200, $element->getMediaImageWidth());
    }

    public function testMediaImageWidthForFullWidth(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 0;
        $element->write();

        // colSize = 12 (full) → > 10 → 1440px
        $this->assertSame(1440, $element->getMediaImageWidth());
    }

    public function testMediaImageHeightForSquareRatio(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->MediaRatio = '1x1';
        $element->write();

        // Square: width == height
        $this->assertSame($element->getMediaImageWidth(), $element->getMediaImageHeight());
    }

    public function testMediaImageHeightForSixteenByNine(): void
    {
        $element = ContentElement::create();
        $element->ContentColumns = 8;
        $element->MediaRatio = '16x9';
        $element->write();

        $width = $element->getMediaImageWidth();
        $expected = (int) round($width * 9 / 16);

        $this->assertSame($expected, $element->getMediaImageHeight());
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

    // --- MediaImageSourceURL without image ---

    public function testMediaImageSourceURLReturnsNullWithoutImage(): void
    {
        $element = ContentElement::create();
        $element->write();

        $this->assertNull($element->getMediaImageSourceURL());
    }
}
