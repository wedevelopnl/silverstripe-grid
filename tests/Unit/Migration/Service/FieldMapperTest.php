<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\LegacyElementReader;
use WeDevelop\Grid\Tests\Unit\Migration\Support\LegacyElementFactory;

#[CoversClass(FieldMapper::class)]
#[CoversClass(LegacyElement::class)]
#[CoversClass(LegacyMediaData::class)]
#[CoversClass(MappedMediaFields::class)]
final class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper();
    }

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

    /**
     * Override-viewport visibility values that do NOT produce an override:
     * they either match the visible default or are "unset". Size/offset match
     * the default in all cases, so only visibility could drive an override.
     *
     * @return iterable<string, array{string|null}>
     */
    public static function noOverrideVisibilityProvider(): iterable
    {
        yield 'visible matches default → no override' => ['visible'];
        yield 'empty string is unset → no override' => [''];
        yield 'null is unset → no override' => [null];
    }

    #[DataProvider('noOverrideVisibilityProvider')]
    public function testOverrideVisibilityMatchingOrUnsetProducesNoOverride(?string $rawVisibility): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => $rawVisibility === null
                ? ['MD' => 'visible']
                : ['MD' => 'visible', 'XS' => $rawVisibility],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testOverrideVisibilityHiddenAgainstVisibleDefaultProducesHiddenOverride(): void
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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function verticalAlignmentProvider(): iterable
    {
        yield 'center CSS class → center' => ['align-items-center', 'center'];
        yield 'end CSS class → bottom' => ['align-items-end', 'bottom'];
        yield 'empty string → top (default)' => ['', 'top'];
        yield 'unknown CSS class → top (fallback)' => ['align-items-start', 'top'];
    }

    #[DataProvider('verticalAlignmentProvider')]
    public function testVerticalAlignmentMapping(string $input, string $expected): void
    {
        $media = new LegacyMediaData(['ContentVerticalAlign' => $input]);

        self::assertSame($expected, $this->mapper->mapMediaFields($media)->VerticalAlignment);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function mediaPositionProvider(): iterable
    {
        yield 'order-1 → first' => ['order-1', 'first'];
        yield 'order-2 → last' => ['order-2', 'last'];
        yield 'responsive order → last-on-desktop' => ['order-1 order-md-2', 'last-on-desktop'];
        yield 'null → first (default)' => [null, 'first'];
        yield 'empty string → first (default)' => ['', 'first'];
        yield 'unknown class → first (fallback)' => ['order-99', 'first'];
    }

    #[DataProvider('mediaPositionProvider')]
    public function testMediaPositionMapping(?string $input, string $expected): void
    {
        $media = new LegacyMediaData(['MediaPosition' => $input]);

        self::assertSame($expected, $this->mapper->mapMediaFields($media)->MediaPosition);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function mediaRatioProvider(): iterable
    {
        yield 'empty string → auto' => ['', 'auto'];
        yield 'null → auto' => [null, 'auto'];
        yield '16x9 passes through' => ['16x9', '16x9'];
        yield '4x3 passes through' => ['4x3', '4x3'];
        yield '1x1 passes through' => ['1x1', '1x1'];
    }

    #[DataProvider('mediaRatioProvider')]
    public function testMediaRatioMapping(?string $input, string $expected): void
    {
        $media = new LegacyMediaData(['MediaRatio' => $input]);

        self::assertSame($expected, $this->mapper->mapMediaFields($media)->MediaRatio);
    }

    /**
     * @return iterable<string, array{string|null, int}>
     */
    public static function contentColumnsProvider(): iterable
    {
        yield '8 string → 8 int' => ['8', 8];
        yield '12 string → 12 int' => ['12', 12];
        yield 'empty string → 0' => ['', 0];
        yield 'null → 0' => [null, 0];
        yield '0 string → 0' => ['0', 0];
        // Leading-numeric but non-numeric: must normalise to 0, not to (int) '3abc' === 3.
        // Also drives the "unrecognised value" warning through the mapper's null logger,
        // which must stay optional.
        yield 'leading-numeric garbage → 0' => ['3abc', 0];
        yield 'non-numeric → 0' => ['abc', 0];
    }

    #[DataProvider('contentColumnsProvider')]
    public function testContentColumnsMapping(?string $input, int $expected): void
    {
        $media = new LegacyMediaData(['ContentColumns' => $input]);

        self::assertSame($expected, $this->mapper->mapMediaFields($media)->ContentColumns);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function gapSizeProvider(): iterable
    {
        yield '0 → 0' => [0, 0];
        yield '2 → 1' => [2, 1];
        yield '3 → 1' => [3, 1];
        yield '5 → 2' => [5, 2];
        yield '7 → 3' => [7, 3];
        yield '9 → 3' => [9, 3];
        yield '11 → 4' => [11, 4];
        yield '16 → 5' => [16, 5];
        yield '17 → 5' => [17, 5];
        yield 'unmapped value → 0 (fallback)' => [99, 0];
    }

    #[DataProvider('gapSizeProvider')]
    public function testGapSizeMapping(int $input, int $expected): void
    {
        $media = new LegacyMediaData(['ExtraColumnGap' => $input]);

        self::assertSame($expected, $this->mapper->mapMediaFields($media)->GapSize);
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

        self::assertSame('https://example.com/video.mp4', $result->VideoURL);
        self::assertSame('youtube', $result->VideoProvider);
        self::assertTrue($result->VideoHasOverlay);
        self::assertSame(42, $result->VideoCustomThumbnailID);
        self::assertSame('My Video', $result->VideoEmbedName);
        self::assertSame('https://youtube.com/embed/abc', $result->VideoEmbedURL);
        self::assertSame('A video', $result->VideoEmbedDescription);
        self::assertSame('https://img.youtube.com/abc.jpg', $result->VideoEmbedThumbnail);
        self::assertSame('2024-01-01', $result->VideoEmbedCreated);
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

        self::assertSame('first', $resultNull->MediaPosition);
        self::assertSame('auto', $resultNull->MediaRatio);
        self::assertSame(0, $resultNull->ContentColumns);

        self::assertSame('first', $resultEmpty->MediaPosition);
        self::assertSame('auto', $resultEmpty->MediaRatio);
        self::assertSame(0, $resultEmpty->ContentColumns);
    }

    /**
     * Each case: [columnCount, sizeInput, offsetInput, expectedWidth, expectedOffset].
     *
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function clampingProvider(): iterable
    {
        yield 'width exceeding columns → clamped to column count' => [12, 15, 0, 12, 0];
        yield 'negative width → treated as "not set" and defaults to full width' => [12, -3, 0, 12, 0];
        yield 'width=0 → treated as "not set" and defaults to full width' => [12, 0, 0, 12, 0];
        yield 'width=1 stays 1 not clamped to 2' => [12, 1, 0, 1, 0];
        yield 'negative offset → clamped to 0' => [12, 6, -2, 6, 0];
        yield 'offset=0 stays 0 not clamped to 1' => [12, 6, 0, 6, 0];
        yield 'offset exceeding max → clamped to columns - 1' => [12, 1, 14, 1, 11];
        yield 'width + offset overflow → offset reduced' => [12, 8, 6, 8, 4];
        yield 'width + offset exactly equals columnCount' => [12, 11, 1, 11, 1];
        yield 'width + offset = columnCount+1 → offset reduced by 1' => [12, 11, 2, 11, 1];
        yield 'both width and offset out of range' => [12, 15, 14, 12, 0];
        yield 'custom column count respected' => [6, 8, 0, 6, 0];
        yield 'valid values unchanged' => [12, 8, 2, 8, 2];
    }

    /**
     * @param positive-int $columnCount
     */
    #[DataProvider('clampingProvider')]
    public function testClamping(int $columnCount, int $sizeInput, int $offsetInput, int $expectedWidth, int $expectedOffset): void
    {
        $mapper = new FieldMapper(columnCount: $columnCount);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => $sizeInput],
            'offsetFields' => ['MD' => $offsetInput],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame($expectedWidth, $settings->default->width, 'width');
        self::assertSame($expectedOffset, $settings->default->offset, 'offset');
    }

    public function testOverrideClampedIndependentlyFromDefault(): void
    {
        $mapper = new FieldMapper(columnCount: 12);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 15],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertSame(8, $settings->default->width);
        self::assertTrue($settings->hasOverride('xs'));
        self::assertSame(12, $settings->getOverride('xs')?->width);
    }

    public function testClampedOverrideMatchingClampedDefaultProducesNoOverride(): void
    {
        // Both MD=15 and XS=20 clamp to width=12, offset=0 — no override needed
        $mapper = new FieldMapper(columnCount: 12);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 15, 'XS' => 20],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testClampingLogsWarningWithElementIdAndViewport(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('Clamped'),
                self::callback(static fn (array $ctx): bool => $ctx['elementId'] === 42 && $ctx['viewport'] === 'default'),
            );

        $mapper = new FieldMapper(columnCount: 12, logger: $logger);
        $element = LegacyElementFactory::content(id: 42, overrides: [
            'sizeFields' => ['MD' => 15],
            'offsetFields' => ['MD' => 0],
        ]);

        $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);
    }

    public function testNoLoggingWhenValuesAreValid(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $mapper = new FieldMapper(columnCount: 12, logger: $logger);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 2],
        ]);

        $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function classNameProvider(): iterable
    {
        yield 'known ElementContent → ContentElement' => [
            'DNADesign\\Elemental\\Models\\ElementContent',
            'WeDevelop\\Grid\\Model\\ContentElement',
        ];
        yield 'unknown class passes through' => [
            'My\\Custom\\Element',
            'My\\Custom\\Element',
        ];
    }

    #[DataProvider('classNameProvider')]
    public function testClassNameResolution(string $input, string $expected): void
    {
        self::assertSame($expected, $this->mapper->resolveClassName($input));
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

    public function testZeroSizeFieldsDefaultToFullWidth(): void
    {
        // Simulates plain elemental where no viewport columns exist — all sizes are 0
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['XS' => 0, 'SM' => 0, 'MD' => 0, 'LG' => 0, 'XL' => 0],
            'offsetFields' => ['XS' => 0, 'SM' => 0, 'MD' => 0, 'LG' => 0, 'XL' => 0],
            'visibilityFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', [
            'XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl',
        ]);

        self::assertSame(12, $settings->default->width, 'Default width should be full column count');
        self::assertSame(0, $settings->default->offset);
        self::assertTrue($settings->default->visible);
        self::assertSame([], $settings->overrides, 'No overrides expected when all viewports are unset');
    }

    public function testZeroSizeFieldsWithCustomColumnCount(): void
    {
        $mapper = new FieldMapper(columnCount: 16);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 0],
            'offsetFields' => ['MD' => 0],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame(16, $settings->default->width, 'Should use custom column count as fallback');
    }

    public function testSizeZeroWithNonZeroOffsetProducesOverride(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 0],
            'offsetFields' => ['MD' => 0, 'XS' => 1],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl']);

        // size=0 but offset=1 → not "unset", override should exist
        self::assertTrue($settings->hasOverride('xs'));
        self::assertSame(1, $settings->getOverride('xs')?->offset);
        // size=0 is NOT > 0, so the override inherits the default width (8) — it is
        // NOT clamped to 1. Pins the `$size > 0` guard against a `>=` mutation that
        // would treat 0 as a real width and emit width=0 (clamped up to 1).
        self::assertSame(8, $settings->getOverride('xs')?->width);
    }

    public function testOverrideViewportSizeFieldMissingDoesNotFabricateOverride(): void
    {
        // The override viewport (XS) has no size, offset, or visibility set at all.
        // The `?? 0` on the size lookup must yield 0 → the unset-skip guard fires →
        // no override. A mutation to `?? 1` would fabricate a width-1 xs override.
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 0],
            'visibilityFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertFalse($settings->hasOverride('xs'));
    }

    public function testSizeZeroWithExplicitVisibilityProducesOverride(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 0],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => null, 'XS' => 'hidden'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl']);

        // size=0, offset=0, but visibility is set → not "unset", override should exist
        self::assertTrue($settings->hasOverride('xs'));
        self::assertFalse($settings->getOverride('xs')?->visible);
    }

    public function testSizeOneWithZeroOffsetAndNullVisibilityProducesOverrideWhenDifferentFromDefault(): void
    {
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 1],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['XS' => 'xs', 'SM' => 'sm', 'MD' => 'md', 'LG' => 'lg', 'XL' => 'xl']);

        // size=1 is > 0, so it's considered "set" and differs from default=8
        self::assertTrue($settings->hasOverride('xs'));
        self::assertSame(1, $settings->getOverride('xs')?->width);
    }

    public function testOverrideWithAllThreeFieldsDifferingFromDefaultProducesOverride(): void
    {
        // Size, offset, and visibility all non-zero/non-null and all differ from default —
        // the "unset" skip guard must evaluate false so the override is emitted.
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 4, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 2],
            'visibilityFields' => ['MD' => 'visible', 'XS' => 'hidden'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertTrue($settings->hasOverride('xs'));
        $xsOverride = $settings->getOverride('xs');
        self::assertNotNull($xsOverride);
        self::assertSame(8, $xsOverride->width);
        self::assertSame(2, $xsOverride->offset);
        self::assertFalse($xsOverride->visible);
    }

    public function testCustomVerticalAlignMapReplacesDefaults(): void
    {
        // When a map is injected, it replaces the constant entirely — the default
        // 'align-items-center' → 'center' mapping must no longer apply.
        $mapper = new FieldMapper(verticalAlignMap: ['custom-class' => 'custom-value']);

        self::assertSame(
            'custom-value',
            $mapper->mapMediaFields(new LegacyMediaData(['ContentVerticalAlign' => 'custom-class']))->VerticalAlignment,
        );
        self::assertSame(
            'top',
            $mapper->mapMediaFields(new LegacyMediaData(['ContentVerticalAlign' => 'align-items-center']))->VerticalAlignment,
            'Default mapping must not apply when overridden',
        );
    }

    public function testCustomMediaPositionMapReplacesDefaults(): void
    {
        $mapper = new FieldMapper(mediaPositionMap: ['custom-order' => 'custom-position']);

        self::assertSame(
            'custom-position',
            $mapper->mapMediaFields(new LegacyMediaData(['MediaPosition' => 'custom-order']))->MediaPosition,
        );
        self::assertSame(
            'first',
            $mapper->mapMediaFields(new LegacyMediaData(['MediaPosition' => 'order-2']))->MediaPosition,
            'Default mapping must not apply when overridden',
        );
    }

    public function testCustomGapSizeMapReplacesDefaults(): void
    {
        // Key 0 exists but maps to 99 instead of 0; entries -1 and 1 are absent so
        // the "?? 0" default in mapMediaFields must look up [0], not some neighbour.
        $mapper = new FieldMapper(gapSizeMap: [0 => 99, 5 => 77]);

        self::assertSame(77, $mapper->mapMediaFields(new LegacyMediaData(['ExtraColumnGap' => 5]))->GapSize);
        self::assertSame(
            0,
            $mapper->mapMediaFields(new LegacyMediaData(['ExtraColumnGap' => 7]))->GapSize,
            'Values outside custom map fall through to 0 fallback',
        );
    }

    public function testCustomGapSizeMapDefaultKeyLookupOnMissingField(): void
    {
        // ExtraColumnGap absent → $gap defaults to 0 → $gapSizeMap[0] → 99.
        // This pins the "?? 0" default literal: if mutated to ?? 1 or ?? -1,
        // those keys aren't in the custom map and the result becomes 0.
        $mapper = new FieldMapper(gapSizeMap: [0 => 99]);

        self::assertSame(99, $mapper->mapMediaFields(new LegacyMediaData([]))->GapSize);
    }

    public function testDefaultViewportVisibilityHiddenProducesFalseDefault(): void
    {
        // Pins `?? true` Coalesce: mutated to `true ?? ...` the default always becomes true,
        // masking explicit 'hidden' on the default viewport.
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 0],
            'visibilityFields' => ['MD' => 'hidden'],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertFalse($settings->default->visible);
    }

    public function testDefaultViewportSizeFieldMissingDefaultsToColumnCount(): void
    {
        // sizeFields key absent → raw width falls back to 0 → > 0 check fails → columnCount.
        // Kills IncrementInteger on `?? 0` (which would yield raw=1, i.e. width=1).
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => [],
            'offsetFields' => ['MD' => 0],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame(12, $settings->default->width);
    }

    public function testDefaultViewportOffsetFieldMissingDefaultsToZero(): void
    {
        // offsetFields key absent → `?? 0` → 0. Kills IncrementInteger (`?? 1` would yield offset=1).
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => [],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame(0, $settings->default->offset);
    }

    public function testOverrideViewportOffsetFieldMissingDefaultsToZero(): void
    {
        // Override viewport has a size present (so it enters the override path) but no
        // offset — the `?? 0` must produce offset 0, not 1 (IncrementInteger mutation).
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 6],
            'offsetFields' => ['MD' => 0],
        ]);

        $settings = $this->mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        self::assertTrue($settings->hasOverride('xs'));
        self::assertSame(0, $settings->getOverride('xs')?->offset);
    }

    /**
     * Each case: [rawVisibility, expectedDefaultVisible, expectsWarning].
     *
     * Drives the default-viewport visibility resolution. '' and null are "not
     * configured" and fall back to the documented default (true). 'visible' and
     * 'hidden' map explicitly. Any other non-empty value is unexpected legacy
     * data: it must NOT silently hide the element (fail closed) — it falls back
     * to the default and logs a warning.
     *
     * @return iterable<string, array{string|null, bool, bool}>
     */
    public static function defaultVisibilityProvider(): iterable
    {
        yield 'empty string → default true, no warning' => ['', true, false];
        yield 'null → default true, no warning' => [null, true, false];
        yield 'visible → true, no warning' => ['visible', true, false];
        yield 'hidden → false, no warning' => ['hidden', false, false];
        yield 'unrecognised value → default true + warning' => ['somethingelse', true, true];
    }

    #[DataProvider('defaultVisibilityProvider')]
    public function testDefaultViewportVisibilityMapping(?string $rawVisibility, bool $expectedVisible, bool $expectsWarning): void
    {
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message) use (&$warnings): void {
                $warnings[] = (string) $message;
            },
        );

        $mapper = new FieldMapper(logger: $logger);
        $element = LegacyElementFactory::content(id: 7, overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 0],
            'visibilityFields' => $rawVisibility === null ? [] : ['MD' => $rawVisibility],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertSame($expectedVisible, $settings->default->visible);

        $unrecognisedWarnings = \array_filter(
            $warnings,
            static fn (string $msg): bool => \str_contains($msg, 'Unrecognised visibility value'),
        );
        if ($expectsWarning) {
            self::assertNotEmpty($unrecognisedWarnings, 'Expected an unrecognised-visibility warning');
        } else {
            self::assertEmpty($unrecognisedWarnings, 'Did not expect an unrecognised-visibility warning');
        }
    }

    public function testUnrecognisedVisibilityWarningContainsElementIdValueAndViewport(): void
    {
        // Pins the warning context keys so the diagnostic stays actionable.
        /** @var array<string, mixed>|null $context */
        $context = null;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('Unrecognised visibility value'),
                self::callback(static function (array $ctx) use (&$context): bool {
                    $context = $ctx;
                    return true;
                }),
            );

        $mapper = new FieldMapper(logger: $logger);
        $element = LegacyElementFactory::content(id: 99, overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 0],
            'visibilityFields' => ['MD' => 'bogus'],
        ]);

        $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertNotNull($context);
        self::assertSame('bogus', $context['value']);
        self::assertSame(99, $context['elementId']);
        self::assertSame('default', $context['viewport']);
    }

    public function testUnrecognisedVisibilityWithNullLoggerDoesNotThrow(): void
    {
        // With no logger injected (the default), an unrecognised visibility value
        // must still resolve cleanly: the `?->warning(...)` null-safe call is a
        // no-op and the element falls back to the documented default (visible=true).
        // A mutation that drops the null-safe operator would fatal here.
        $mapper = new FieldMapper();
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8],
            'offsetFields' => ['MD' => 0],
            'visibilityFields' => ['MD' => 'bogus'],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertTrue($settings->default->visible);
    }

    public function testUnrecognisedVisibilityOnOverrideViewportFallsBackToDefaultWithWarning(): void
    {
        // An unrecognised override-viewport visibility must not fabricate a
        // hidden override; it falls back to the default's visibility (true here)
        // so no visibility-driven override is produced, and a warning is logged.
        $warnings = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(
            static function (string|\Stringable $message, array $ctx) use (&$warnings): void {
                $warnings[] = ['message' => (string) $message, 'context' => $ctx];
            },
        );

        $mapper = new FieldMapper(logger: $logger);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 8, 'XS' => 8],
            'offsetFields' => ['MD' => 0, 'XS' => 0],
            'visibilityFields' => ['MD' => 'visible', 'XS' => 'weird'],
        ]);

        $settings = $mapper->mapGridSettings($element, 'MD', ['MD' => 'md', 'XS' => 'xs']);

        // Same size/offset as default, visibility unrecognised → treated as unset → no override.
        self::assertFalse($settings->hasOverride('xs'));

        $xsWarnings = \array_filter(
            $warnings,
            static fn (array $w): bool => \str_contains($w['message'], 'Unrecognised visibility value')
                && ($w['context']['viewport'] ?? null) === 'xs',
        );
        self::assertNotEmpty($xsWarnings, 'Expected a warning tagged with the xs viewport');
    }

    public function testClampLogContextContainsOldAndNewWidthAndOffset(): void
    {
        // Pins the log context array keys: removing 'oldWidth'/'oldOffset' (or replacing
        // `=>` with `>`) must fail the assertion.
        /** @var array<string, mixed>|null $context */
        $context = null;

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::anything(),
                self::callback(static function (array $ctx) use (&$context): bool {
                    $context = $ctx;
                    return true;
                }),
            );

        $mapper = new FieldMapper(columnCount: 12, logger: $logger);
        $element = LegacyElementFactory::content(overrides: [
            'sizeFields' => ['MD' => 15],   // clamps to 12
            'offsetFields' => ['MD' => 14], // clamps via reclamp to 0
        ]);

        $mapper->mapGridSettings($element, 'MD', ['MD' => 'md']);

        self::assertNotNull($context);
        self::assertSame(15, $context['oldWidth']);
        self::assertSame(12, $context['newWidth']);
        self::assertSame(14, $context['oldOffset']);
        self::assertSame(0, $context['newOffset']);
    }

    public function testMediaImageIDDefaultsToZeroWhenAbsent(): void
    {
        // `?? 0` default: mutants would yield 1 (Inc), -1 (Dec), or passthrough (Coalesce).
        $result = $this->mapper->mapMediaFields(new LegacyMediaData([]));

        self::assertSame(0, $result->MediaImageID);
    }

    public function testMediaImageIDIsPreservedWhenPresent(): void
    {
        // Kills `Coalesce` mutant `0 ?? $fields['MediaImageID']` (always returns 0).
        $result = $this->mapper->mapMediaFields(new LegacyMediaData(['MediaImageID' => 42]));

        self::assertSame(42, $result->MediaImageID);
    }

    public function testVideoCustomThumbnailIDDefaultsToZeroWhenAbsent(): void
    {
        $result = $this->mapper->mapMediaFields(new LegacyMediaData([]));

        self::assertSame(0, $result->VideoCustomThumbnailID);
    }

    public function testVideoHasOverlayDefaultsToFalseWhenAbsent(): void
    {
        // Kills `FalseValue` mutant `?? true`.
        $result = $this->mapper->mapMediaFields(new LegacyMediaData([]));

        self::assertFalse($result->VideoHasOverlay);
    }

    public function testMediaTypeAndCaptionArePreservedWhenPresent(): void
    {
        // Kills `Coalesce` mutants at MediaType/MediaCaption (`'' ?? $fields[...]` always yields '').
        $result = $this->mapper->mapMediaFields(new LegacyMediaData([
            'MediaType' => 'image',
            'MediaCaption' => 'Hero shot',
        ]));

        self::assertSame('image', $result->MediaType);
        self::assertSame('Hero shot', $result->MediaCaption);
    }

    public function testMissingMediaColumnLogsWarning(): void
    {
        // Keys entirely absent from fields (schema-missing) must trigger a warning
        // naming the absent column(s) and the label "schema-missing".
        // Only ContentColumns and ContentVerticalAlign are present; the remaining
        // expected keys are absent → warning must name at least MediaImageID.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(
                self::stringContains('schema-missing'),
                self::callback(static fn (array $ctx): bool =>
                    \is_string($ctx['columns'] ?? null)
                    && \str_contains($ctx['columns'], 'MediaImageID')
                ),
            );

        $mapper = new FieldMapper(logger: $logger);
        $mapper->mapMediaFields(new LegacyMediaData([
            'ContentColumns' => '6',
            'ContentVerticalAlign' => '',
            // All other expected media keys deliberately omitted → schema-missing
        ]));
    }

    public function testPresentButEmptyMediaColumnDoesNotLogMissing(): void
    {
        // Keys present with empty/null values are normal empty data, not schema gaps.
        // No schema-missing warning must be emitted when all expected keys exist.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $mapper = new FieldMapper(logger: $logger);
        $mapper->mapMediaFields(new LegacyMediaData([
            'ContentColumns'             => '',
            'ContentVerticalAlign'       => '',
            'ExtraColumnGap'             => 0,
            'MediaType'                  => '',
            'MediaCaption'               => '',
            'MediaRatio'                 => null,
            'MediaPosition'              => null,
            'MediaImageID'               => null,
            'MediaVideoFullURL'          => '',
            'MediaVideoProvider'         => '',
            'MediaVideoHasOverlay'       => false,
            'MediaVideoCustomThumbnailID' => 0,
            'MediaVideoEmbeddedName'     => '',
            'MediaVideoEmbeddedURL'      => '',
            'MediaVideoEmbeddedDescription' => '',
            'MediaVideoEmbeddedThumbnail' => '',
            'MediaVideoEmbeddedCreated'  => '',
        ]));
    }

    public function testNonNumericContentColumnsLogsWarningAndResetsToZero(): void
    {
        // A non-empty, non-numeric ContentColumns value (e.g. corrupt legacy data)
        // must log a warning naming the field and the offending value, then fall
        // back to 0. All other expected keys are present so no schema-missing
        // warning fires — the assertion is ContentColumns-specific.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('ContentColumns'),
                self::callback(static fn (array $ctx): bool => ($ctx['value'] ?? null) === 'abc'),
            );

        $mapper = new FieldMapper(logger: $logger);
        $result = $mapper->mapMediaFields(new LegacyMediaData(
            \array_merge(self::allMediaFieldsPresent(), ['ContentColumns' => 'abc'])
        ));

        self::assertSame(0, $result->ContentColumns);
    }

    public function testNumericContentColumnsDoesNotWarn(): void
    {
        // A numeric string ContentColumns value must be converted to int with no
        // warning at all. All expected keys are present so no schema-missing
        // warning fires either.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $mapper = new FieldMapper(logger: $logger);
        $result = $mapper->mapMediaFields(new LegacyMediaData(
            \array_merge(self::allMediaFieldsPresent(), ['ContentColumns' => '3'])
        ));

        self::assertSame(3, $result->ContentColumns);
    }

    public function testEmptyContentColumnsDoesNotWarn(): void
    {
        // Empty string and null both represent "not set" and must silently yield 0
        // without any warning — they are not bad data.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $mapper = new FieldMapper(logger: $logger);

        $resultEmpty = $mapper->mapMediaFields(new LegacyMediaData(
            \array_merge(self::allMediaFieldsPresent(), ['ContentColumns' => ''])
        ));
        self::assertSame(0, $resultEmpty->ContentColumns);

        $resultNull = $mapper->mapMediaFields(new LegacyMediaData(
            \array_merge(self::allMediaFieldsPresent(), ['ContentColumns' => null])
        ));
        self::assertSame(0, $resultNull->ContentColumns);
    }

    public function testExpectedMediaKeysMatchesMediaFieldsMinusHtml(): void
    {
        // EXPECTED_MEDIA_KEYS is the non-HTML subset of LegacyElementReader::MEDIA_FIELDS.
        // HTML is handled separately via LegacyElement->extraData and is intentionally
        // excluded from the mapping path. Both constants are private, so reflection
        // is used here — we do NOT widen visibility just to enable a test.
        //
        // Order is not semantically significant for these field lists, so
        // assertEqualsCanonicalizing is used: a reordering won't produce a false
        // failure, but a missing or extra entry will.
        //
        // This test will FAIL if a field is added to MEDIA_FIELDS but not to
        // EXPECTED_MEDIA_KEYS (or vice versa), surfacing the drift immediately.
        $expectedMediaKeys = (new \ReflectionClassConstant(FieldMapper::class, 'EXPECTED_MEDIA_KEYS'))->getValue();
        $mediaFields = (new \ReflectionClassConstant(LegacyElementReader::class, 'MEDIA_FIELDS'))->getValue();

        $mediaFieldsWithoutHtml = \array_values(\array_filter(
            $mediaFields,
            static fn (string $field): bool => $field !== 'HTML',
        ));

        self::assertEqualsCanonicalizing(
            $mediaFieldsWithoutHtml,
            $expectedMediaKeys,
            'EXPECTED_MEDIA_KEYS must equal MEDIA_FIELDS minus "HTML"; '
            . 'add any new media field to both constants, or document the intentional exclusion.',
        );
    }

    /**
     * Full set of expected media keys with inert values.
     * Providing every key prevents the schema-missing warning from Task 4,
     * so warning-isolation tests can use expects(never) cleanly.
     *
     * @return array<string, string|int|bool|null>
     */
    private static function allMediaFieldsPresent(): array
    {
        return [
            'ContentColumns'                 => '',
            'ContentVerticalAlign'           => '',
            'ExtraColumnGap'                 => 0,
            'MediaType'                      => '',
            'MediaCaption'                   => '',
            'MediaRatio'                     => null,
            'MediaPosition'                  => null,
            'MediaImageID'                   => null,
            'MediaVideoFullURL'              => '',
            'MediaVideoProvider'             => '',
            'MediaVideoHasOverlay'           => false,
            'MediaVideoCustomThumbnailID'    => 0,
            'MediaVideoEmbeddedName'         => '',
            'MediaVideoEmbeddedURL'          => '',
            'MediaVideoEmbeddedDescription'  => '',
            'MediaVideoEmbeddedThumbnail'    => '',
            'MediaVideoEmbeddedCreated'      => '',
        ];
    }
}
