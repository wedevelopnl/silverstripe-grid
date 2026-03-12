<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use SilverStripe\Assets\Image;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Tests\Unit\Extensions\Stub\BlockMediaOwnerStub;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Unit tests for BlockMediaExtension pure logic.
 *
 * Uses mock adapters to verify composition logic without coupling to a CSS framework.
 */
#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaExtensionTest extends TestCase
{
    private GridAdapterInterface&MockObject $gridAdapter;

    private ContentLayoutAdapterInterface&MockObject $contentLayoutAdapter;

    private Image&MockObject $image;

    private BlockMediaOwnerStub $owner;

    private BlockMediaExtension $extension;

    protected function setUp(): void
    {
        $this->gridAdapter = $this->createMock(GridAdapterInterface::class);
        $this->gridAdapter->method('getRowClasses')->willReturn('mock-row');
        $this->gridAdapter->method('getColumnCount')->willReturn(12);
        $this->gridAdapter->method('getContainerMaxWidth')->willReturn(1320);

        $this->contentLayoutAdapter = $this->createMock(ContentLayoutAdapterInterface::class);

        $this->image = $this->createMock(Image::class);
        $this->image->method('exists')->willReturn(false);

        $this->owner = new BlockMediaOwnerStub();
        $this->owner->gridAdapter = $this->gridAdapter;
        $this->owner->setMediaImage($this->image);

        $this->extension = new BlockMediaExtension();
        $this->extension->setOwner($this->owner);
        $this->extension->contentLayoutAdapter = $this->contentLayoutAdapter;
    }

    // --- Enum parsing ---

    public function testMediaPositionEnumDefaultsToFirst(): void
    {
        $this->assertSame(MediaPosition::First, $this->extension->getMediaPositionEnum());
    }

    public function testVerticalAlignmentEnumDefaultsToCenter(): void
    {
        $this->assertSame(VerticalAlignment::Center, $this->extension->getVerticalAlignmentEnum());
    }

    public function testAspectRatioEnumDefaultsToAuto(): void
    {
        $this->assertSame(AspectRatio::Auto, $this->extension->getAspectRatioEnum());
    }

    #[DataProvider('mediaPositionProvider')]
    public function testMediaPositionEnumParsesCorrectly(string $value, MediaPosition $expected): void
    {
        $this->owner->MediaPosition = $value;

        $this->assertSame($expected, $this->extension->getMediaPositionEnum());
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
        $this->assertFalse($this->extension->hasMedia());
    }

    public function testHasMediaReturnsFalseForImageWithoutAttachment(): void
    {
        $this->owner->MediaType = 'image';

        $this->assertFalse($this->extension->hasMedia());
    }

    public function testHasMediaReturnsFalseForVideoWithoutURL(): void
    {
        $this->owner->MediaType = 'video';

        $this->assertFalse($this->extension->hasMedia());
    }

    public function testHasMediaReturnsTrueForVideoWithURL(): void
    {
        $this->owner->MediaType = 'video';
        $this->owner->VideoURL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        $this->assertTrue($this->extension->hasMedia());
    }

    // --- isLayoutMode ---

    public function testIsLayoutModeReturnsFalseWithoutContentColumns(): void
    {
        $this->owner->MediaType = 'video';
        $this->owner->VideoURL = 'https://example.com/video';

        $this->assertFalse($this->extension->isLayoutMode());
    }

    public function testIsLayoutModeReturnsFalseWithoutMedia(): void
    {
        $this->owner->ContentColumns = 8;

        $this->assertFalse($this->extension->isLayoutMode());
    }

    public function testIsLayoutModeReturnsTrueWithMediaAndColumns(): void
    {
        $this->owner->MediaType = 'video';
        $this->owner->VideoURL = 'https://example.com/video';
        $this->owner->ContentColumns = 8;

        $this->assertTrue($this->extension->isLayoutMode());
    }

    // --- Layout classes ---

    public function testLayoutRowClassesCombinesRowAndAlignment(): void
    {
        $this->owner->VerticalAlignment = 'center';

        $this->contentLayoutAdapter->method('getVerticalAlignmentClass')
            ->with(VerticalAlignment::Center)
            ->willReturn('mock-align-center');

        $result = $this->extension->getLayoutRowClasses();

        $this->assertSame('mock-row mock-align-center', $result);
    }

    public function testMediaColumnClassesWithBaseClass(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaPosition = 'first';

        $this->contentLayoutAdapter->method('getBaseColumnClass')->willReturn('mock-base-col');
        $this->contentLayoutAdapter->method('getMediaWidthClass')->with(8)->willReturn('mock-media-w4');
        $this->contentLayoutAdapter->method('getMediaOrderClasses')
            ->with(MediaPosition::First)
            ->willReturn('mock-order-1');

        $result = $this->extension->getMediaColumnClasses();

        $this->assertSame('mock-base-col mock-media-w4 mock-order-1', $result);
    }

    public function testMediaColumnClassesWithoutBaseClass(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaPosition = 'first';

        $this->contentLayoutAdapter->method('getBaseColumnClass')->willReturn(null);
        $this->contentLayoutAdapter->method('getMediaWidthClass')->with(8)->willReturn('mock-media-w4');
        $this->contentLayoutAdapter->method('getMediaOrderClasses')
            ->with(MediaPosition::First)
            ->willReturn('mock-order-1');

        $result = $this->extension->getMediaColumnClasses();

        $this->assertSame('mock-media-w4 mock-order-1', $result);
    }

    public function testContentColumnClasses(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaPosition = 'first';

        $this->contentLayoutAdapter->method('getBaseColumnClass')->willReturn(null);
        $this->contentLayoutAdapter->method('getContentWidthClass')->with(8)->willReturn('mock-content-w8');
        $this->contentLayoutAdapter->method('getContentOrderClasses')
            ->with(MediaPosition::First)
            ->willReturn('mock-order-2');

        $result = $this->extension->getContentColumnClasses();

        $this->assertSame('mock-content-w8 mock-order-2', $result);
    }

    public function testContentPaddingClassesWithGap(): void
    {
        $this->owner->GapSize = 3;
        $this->owner->MediaPosition = 'first';

        $this->contentLayoutAdapter->method('getPaddingClass')
            ->with('left', 3)
            ->willReturn('mock-ps-3');

        $this->assertSame('mock-ps-3', $this->extension->getContentPaddingClasses());
    }

    public function testContentPaddingClassesWithZeroGap(): void
    {
        $this->owner->GapSize = 0;

        $this->assertSame('', $this->extension->getContentPaddingClasses());
    }

    public function testContentPaddingDirectionForLastPosition(): void
    {
        $this->owner->GapSize = 3;
        $this->owner->MediaPosition = 'last';

        $this->contentLayoutAdapter->method('getPaddingClass')
            ->with('right', 3)
            ->willReturn('mock-pe-3');

        $this->assertSame('mock-pe-3', $this->extension->getContentPaddingClasses());
    }

    // --- Aspect ratio ---

    public function testMediaRatioClassReturnsNullForAuto(): void
    {
        $this->owner->MediaRatio = 'auto';

        $this->contentLayoutAdapter->method('getAspectRatioClass')
            ->with(AspectRatio::Auto)
            ->willReturn(null);

        $this->assertNull($this->extension->getMediaRatioClass());
    }

    public function testMediaRatioClassDelegatesToAdapter(): void
    {
        $this->owner->MediaRatio = '16x9';

        $this->contentLayoutAdapter->method('getAspectRatioClass')
            ->with(AspectRatio::SixteenByNine)
            ->willReturn('mock-ratio-16x9');

        $this->assertSame('mock-ratio-16x9', $this->extension->getMediaRatioClass());
    }

    // --- Image sizing ---

    public function testMediaImageWidthForSmallMediaColumn(): void
    {
        $this->owner->ContentColumns = 8;

        // colSize = 12 - 8 = 4, round(1320 * 4 / 12) = 440
        $this->assertSame(440, $this->extension->getMediaImageWidth());
    }

    public function testMediaImageWidthForMediumMediaColumn(): void
    {
        $this->owner->ContentColumns = 4;

        // colSize = 12 - 4 = 8, round(1320 * 8 / 12) = 880
        $this->assertSame(880, $this->extension->getMediaImageWidth());
    }

    public function testMediaImageWidthForFullWidth(): void
    {
        $this->owner->ContentColumns = 0;

        // colSize = 12 (full), round(1320 * 12 / 12) = 1320
        $this->assertSame(1320, $this->extension->getMediaImageWidth());
    }

    public function testMediaImageHeightForSquareRatio(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = '1x1';

        $this->assertSame(
            $this->extension->getMediaImageWidth(),
            $this->extension->getMediaImageHeight(),
        );
    }

    public function testMediaImageHeightForSixteenByNine(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = '16x9';

        $width = $this->extension->getMediaImageWidth();
        $expected = (int) round($width * 9 / 16);

        $this->assertSame($expected, $this->extension->getMediaImageHeight());
    }

    // --- Height from source (Auto ratio) ---

    public function testMediaImageHeightFromSourceDimensions(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = 'auto';

        $image = $this->createMock(Image::class);
        $image->method('exists')->willReturn(true);
        $image->method('getWidth')->willReturn(800);
        $image->method('getHeight')->willReturn(600);
        $this->owner->setMediaImage($image);

        // width = 440, height = round(440 * 600 / 800) = 330
        $this->assertSame(330, $this->extension->getMediaImageHeight());
    }

    public function testMediaImageHeightFallsBackToWidthWithoutImage(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = 'auto';

        // Default mock image does not exist → height equals width
        $this->assertSame(
            $this->extension->getMediaImageWidth(),
            $this->extension->getMediaImageHeight(),
        );
    }

    public function testMediaImageHeightFallsBackToWidthForZeroWidthSource(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = 'auto';

        $image = $this->createMock(Image::class);
        $image->method('exists')->willReturn(true);
        $image->method('getWidth')->willReturn(0);
        $image->method('getHeight')->willReturn(0);
        $this->owner->setMediaImage($image);

        $this->assertSame(
            $this->extension->getMediaImageWidth(),
            $this->extension->getMediaImageHeight(),
        );
    }

    // --- Image source URL ---

    public function testMediaImageSourceURLReturnsNullWithoutImage(): void
    {
        $this->assertNull($this->extension->getMediaImageSourceURL());
    }

    public function testMediaImageSourceURLUsesScaleWidthForAutoRatio(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = 'auto';

        $resized = $this->createMock(Image::class);
        $resized->method('getURL')->willReturn('https://example.com/scaled.jpg');

        $image = $this->createMock(Image::class);
        $image->method('exists')->willReturn(true);
        $image->method('getWidth')->willReturn(800);
        $image->method('getHeight')->willReturn(600);
        $image->expects($this->once())
            ->method('ScaleWidth')
            ->with(440)
            ->willReturn($resized);

        $this->owner->setMediaImage($image);

        $this->assertSame('https://example.com/scaled.jpg', $this->extension->getMediaImageSourceURL());
    }

    public function testMediaImageSourceURLUsesFillForFixedRatio(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = '16x9';

        $width = 440;
        $height = (int) round($width * 9 / 16);

        $resized = $this->createMock(Image::class);
        $resized->method('getURL')->willReturn('https://example.com/filled.jpg');

        $image = $this->createMock(Image::class);
        $image->method('exists')->willReturn(true);
        $image->expects($this->once())
            ->method('Fill')
            ->with($width, $height)
            ->willReturn($resized);

        $this->owner->setMediaImage($image);

        $this->assertSame('https://example.com/filled.jpg', $this->extension->getMediaImageSourceURL());
    }

    public function testMediaImageSourceURLReturnsNullWhenResizeFails(): void
    {
        $this->owner->ContentColumns = 8;
        $this->owner->MediaRatio = 'auto';

        $image = $this->createMock(Image::class);
        $image->method('exists')->willReturn(true);
        $image->method('getWidth')->willReturn(800);
        $image->method('getHeight')->willReturn(600);
        $image->method('ScaleWidth')->willReturn(null);

        $this->owner->setMediaImage($image);

        $this->assertNull($this->extension->getMediaImageSourceURL());
    }
}
