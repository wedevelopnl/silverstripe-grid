<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;

#[CoversClass(FieldMapper::class)]
final class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper();
    }

    // ─── Grid settings: default viewport ──────────────────────────────────────

    public function testDefaultViewportExtractedCorrectly(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 2],
            'visibilityFields' => ['MD' => 'visible'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);
    }

    public function testNonDefaultViewportWithDifferentSizeProducesOverride(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XL' => 12],
            'offsetFields' => ['MD' => 0, 'XL' => 0],
            'visibilityFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XL' => 'xl']);

        self::assertTrue($settings->hasOverride('xl'));
        self::assertSame(12, $settings->getOverride('xl')?->width);
    }

    public function testSizeZeroMeansNotSetAndIsSkipped(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 0],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testVisibilityVisibleMapsToTrue(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'SM' => 8],
            'offsetFields' => ['MD' => 0, 'SM' => 0],
            'visibilityFields' => ['MD' => 'visible', 'SM' => 'visible'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'SM' => 'sm']);

        // SM has same config as default — no override needed
        self::assertFalse($settings->hasOverride('sm'));
        self::assertTrue($settings->default->visible);
    }

    public function testVisibilityHiddenMapsToFalse(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => 'visible', 'XS' => 'hidden'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertTrue($settings->hasOverride('xs'));
        self::assertFalse($settings->getOverride('xs')?->visible);
    }

    public function testVisibilityEmptyStringIsSkipped(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => 'visible', 'XS' => ''],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        // XS same size/offset as default, visibility not set → no override
        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testVisibilityNullIsSkipped(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => 'visible', 'XS' => null],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testViewportKeyMappingApplied(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 4],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        // Uppercase 'XS' maps to lowercase 'xs' override key
        self::assertTrue($settings->hasOverride('xs'));
        self::assertFalse($settings->hasOverride('XS'));
    }

    public function testAllViewportsSameAsDefaultProducesNoOverrides(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'SM' => 8, 'LG' => 8],
            'offsetFields' => ['MD' => 0, 'SM' => 0, 'LG' => 0],
            'visibilityFields' => ['MD' => 'visible', 'SM' => 'visible', 'LG' => 'visible'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'SM' => 'sm', 'LG' => 'lg']);

        self::assertSame([], $settings->overrides);
    }

    public function testFullExampleProducesCorrectGridSettings(): void
    {
        // SizeMD=8, OffsetMD=2, SizeXL=12, VisibilityXS=hidden
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XL' => 12, 'XS' => 8],
            'offsetFields' => ['MD' => 2, 'XL' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => 'visible', 'XS' => 'hidden'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', [
            'XS' => 'xs',
            'SM' => 'sm',
            'MD' => 'md',
            'LG' => 'lg',
            'XL' => 'xl',
        ]);

        // Default from MD
        self::assertSame(8, $settings->default->width);
        self::assertSame(2, $settings->default->offset);
        self::assertTrue($settings->default->visible);

        // XL override: different size, same visibility as default
        self::assertTrue($settings->hasOverride('xl'));
        $xlOverride = $settings->getOverride('xl');
        self::assertNotNull($xlOverride);
        self::assertSame(12, $xlOverride->width);
        self::assertSame(0, $xlOverride->offset);
        self::assertTrue($xlOverride->visible);

        // XS override: hidden visibility differs from default
        self::assertTrue($settings->hasOverride('xs'));
        $xsOverride = $settings->getOverride('xs');
        self::assertNotNull($xsOverride);
        self::assertFalse($xsOverride->visible);
    }

    // ─── Media field mapping ───────────────────────────────────────────────────

    public function testContentVerticalAlignCenterClassMapsToCenter(): void
    {
        $media = new LegacyMediaData(['ContentVerticalAlign' => 'align-items-center']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('center', $result['VerticalAlignment']);
    }

    public function testContentVerticalAlignEmptyMapsToTop(): void
    {
        $media = new LegacyMediaData(['ContentVerticalAlign' => '']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('top', $result['VerticalAlignment']);
    }

    public function testContentVerticalAlignEndClassMapsToBottom(): void
    {
        $media = new LegacyMediaData(['ContentVerticalAlign' => 'align-items-end']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('bottom', $result['VerticalAlignment']);
    }

    public function testMediaPositionOrder1MapsToFirst(): void
    {
        $media = new LegacyMediaData(['MediaPosition' => 'order-1']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('first', $result['MediaPosition']);
    }

    public function testMediaPositionOrder2MapsToLast(): void
    {
        $media = new LegacyMediaData(['MediaPosition' => 'order-2']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('last', $result['MediaPosition']);
    }

    public function testMediaPositionResponsiveClassMapsToLastOnDesktop(): void
    {
        $media = new LegacyMediaData(['MediaPosition' => 'order-1 order-md-2']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('last-on-desktop', $result['MediaPosition']);
    }

    public function testMediaPositionNullDefaultsToFirst(): void
    {
        $media = new LegacyMediaData(['MediaPosition' => null]);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('first', $result['MediaPosition']);
    }

    public function testMediaPositionEmptyStringDefaultsToFirst(): void
    {
        $media = new LegacyMediaData(['MediaPosition' => '']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('first', $result['MediaPosition']);
    }

    public function testFieldRenamesAreApplied(): void
    {
        $media = new LegacyMediaData([
            'MediaVideoFullURL' => 'https://example.com/video.mp4',
            'MediaVideoProvider' => 'youtube',
            'MediaVideoHasOverlay' => true,
            'MediaVideoCustomThumbnailID' => 42,
            'MediaVideoEmbeddedName' => 'My Video',
            'MediaVideoEmbeddedURL' => 'https://youtube.com/embed/abc',
            'MediaVideoEmbeddedDescription' => 'A video',
            'MediaVideoEmbeddedThumbnail' => 'https://img.youtube.com/abc.jpg',
            'MediaVideoEmbeddedCreated' => '2024-01-01',
        ]);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('https://example.com/video.mp4', $result['VideoURL']);
        self::assertSame('youtube', $result['VideoProvider']);
        self::assertTrue($result['VideoHasOverlay']);
        self::assertSame(42, $result['VideoCustomThumbnailID']);
        self::assertSame('My Video', $result['VideoEmbedName']);
        self::assertSame('https://youtube.com/embed/abc', $result['VideoEmbedURL']);
        self::assertSame('A video', $result['VideoEmbedDescription']);
        self::assertSame('https://img.youtube.com/abc.jpg', $result['VideoEmbedThumbnail']);
        self::assertSame('2024-01-01', $result['VideoEmbedCreated']);

        // Old names should not appear in output
        self::assertArrayNotHasKey('MediaVideoFullURL', $result);
        self::assertArrayNotHasKey('MediaVideoProvider', $result);
    }

    public function testExtraColumnGapMapsToGapSizeWithScaling(): void
    {
        $media7 = new LegacyMediaData(['ExtraColumnGap' => 7]);
        $media17 = new LegacyMediaData(['ExtraColumnGap' => 17]);
        $media0 = new LegacyMediaData(['ExtraColumnGap' => 0]);

        self::assertSame(3, $this->mapper->mapMediaFields($media7)['GapSize']);
        self::assertSame(5, $this->mapper->mapMediaFields($media17)['GapSize']);
        self::assertSame(0, $this->mapper->mapMediaFields($media0)['GapSize']);
    }

    public function testContentColumnsStringToInt(): void
    {
        $media8 = new LegacyMediaData(['ContentColumns' => '8']);
        $mediaEmpty = new LegacyMediaData(['ContentColumns' => '']);
        $mediaNull = new LegacyMediaData(['ContentColumns' => null]);

        self::assertSame(8, $this->mapper->mapMediaFields($media8)['ContentColumns']);
        self::assertSame(0, $this->mapper->mapMediaFields($mediaEmpty)['ContentColumns']);
        self::assertSame(0, $this->mapper->mapMediaFields($mediaNull)['ContentColumns']);
    }

    public function testMediaRatioEmptyStringMapsToAuto(): void
    {
        $media = new LegacyMediaData(['MediaRatio' => '']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('auto', $result['MediaRatio']);
    }

    public function testMediaRatioNullMapsToAuto(): void
    {
        $media = new LegacyMediaData(['MediaRatio' => null]);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('auto', $result['MediaRatio']);
    }

    public function testMediaRatioValidValuePassesThrough(): void
    {
        $media = new LegacyMediaData(['MediaRatio' => '16x9']);

        $result = $this->mapper->mapMediaFields($media);

        self::assertSame('16x9', $result['MediaRatio']);
    }

    public function testNullAndEmptyStringBothTreatedAsNotSetForOptionalFields(): void
    {
        $mediaNull = new LegacyMediaData([
            'MediaPosition' => null,
            'MediaRatio' => null,
            'ContentColumns' => null,
        ]);
        $mediaEmpty = new LegacyMediaData([
            'MediaPosition' => '',
            'MediaRatio' => '',
            'ContentColumns' => '',
        ]);

        $resultNull = $this->mapper->mapMediaFields($mediaNull);
        $resultEmpty = $this->mapper->mapMediaFields($mediaEmpty);

        self::assertSame('first', $resultNull['MediaPosition']);
        self::assertSame('auto', $resultNull['MediaRatio']);
        self::assertSame(0, $resultNull['ContentColumns']);

        self::assertSame('first', $resultEmpty['MediaPosition']);
        self::assertSame('auto', $resultEmpty['MediaRatio']);
        self::assertSame(0, $resultEmpty['ContentColumns']);
    }

    // ─── ClassName resolution ──────────────────────────────────────────────────

    public function testKnownOldClassNameResolvesToNewClassName(): void
    {
        $result = $this->mapper->resolveClassName('DNADesign\\Elemental\\Models\\ElementContent');

        self::assertSame('WeDevelop\\Grid\\Model\\ContentElement', $result);
    }

    public function testUnknownClassNamePassesThroughUnchanged(): void
    {
        $result = $this->mapper->resolveClassName('My\\Custom\\Element');

        self::assertSame('My\\Custom\\Element', $result);
    }

    public function testCustomClassNameMapOverridesDefault(): void
    {
        $mapper = new FieldMapper(classNameMap: [
            'Old\\Class' => 'New\\Class',
        ]);

        self::assertSame('New\\Class', $mapper->resolveClassName('Old\\Class'));
        // Default mapping no longer present when custom map is provided
        self::assertSame(
            'DNADesign\\Elemental\\Models\\ElementContent',
            $mapper->resolveClassName('DNADesign\\Elemental\\Models\\ElementContent'),
        );
    }
}
