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

    // ─── Grid settings: viewport overrides ────────────────────────────────────

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

    // ─── Media field mapping: data providers ──────────────────────────────────

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

    // ─── Media field mapping: standalone tests ────────────────────────────────

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

    // ─── Grid settings: clamping data provider ───────────────────────────────

    /**
     * Each case: [columnCount, sizeInput, offsetInput, expectedWidth, expectedOffset].
     *
     * @return iterable<string, array{int, int, int, int, int}>
     */
    public static function clampingProvider(): iterable
    {
        yield 'width exceeding columns → clamped to column count' => [12, 15, 0, 12, 0];
        yield 'width below 1 → clamped to 1' => [12, -3, 0, 1, 0];
        yield 'negative offset → clamped to 0' => [12, 6, -2, 6, 0];
        yield 'offset exceeding max → clamped to columns - 1' => [12, 1, 14, 1, 11];
        yield 'width + offset overflow → offset reduced' => [12, 8, 6, 8, 4];
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

    // ─── ClassName resolution ─────────────────────────────────────────────────

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
}
