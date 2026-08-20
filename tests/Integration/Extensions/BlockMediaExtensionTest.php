<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Page;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Adapter\TailwindAdapter;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Tests\Integration\Support\RestoresGridAdapterEnv;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaExtensionTest extends SapphireTest
{
    use DisablesAutoScaffolding;
    use RestoresGridAdapterEnv;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private const TEST_IMAGE_PATH = __DIR__ . '/../../E2E/Fixture/assets/test-image.png';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();

        // Pin the active adapter to the default preset (Tailwind) so the
        // extension's layout/dimension output is deterministic regardless of the
        // container's configured SS_GRID_ADAPTER. The env var (not a registered
        // instance) is used so the rebuildGridAdapter() re-resolution in the
        // total_columns/container_max_width override tests still yields Tailwind.
        $this->captureGridAdapterEnv();
        $this->pinAdapterEnv('tailwind');
        $this->rebuildGridAdapter();

        TestAssetStore::activate('BlockMediaExtensionTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        $this->restoreGridAdapterEnv();
        parent::tearDown();
    }

    /**
     * Write a real PNG (200x150, non-square) into the active TestAssetStore and
     * attach it to the element so getMediaImage()->exists() is true and the
     * source dimensions drive the Auto aspect-ratio path.
     */
    private function attachRealImage(ContentElement $element): Image
    {
        $image = Image::create();
        $image->setFromLocalFile(self::TEST_IMAGE_PATH, 'media-test.png');
        $image->write();

        $element->MediaType = 'image';
        $element->MediaImageID = $image->ID;
        $element->write();

        return $image;
    }

    /**
     * Rebuild the GridAdapter singleton so a Config change to total_columns
     * (read in the adapter constructor) takes effect for elements created after
     * this call.
     */
    private function rebuildGridAdapter(): void
    {
        Injector::inst()->unregisterNamedObject(GridAdapterInterface::class);
    }

    private function createContentElement(): ContentElement
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        return GridTreeFactory::contentElement($column, title: 'Media Test');
    }

    public function testGetMediaPositionEnumReturnsStoredValue(): void
    {
        $element = $this->createContentElement();
        $element->MediaPosition = MediaPosition::Last->value;

        self::assertSame(MediaPosition::Last, $element->getMediaPositionEnum());
    }

    public function testGetMediaPositionEnumFallsBackToFirst(): void
    {
        $element = $this->createContentElement();
        $element->MediaPosition = '';

        self::assertSame(MediaPosition::First, $element->getMediaPositionEnum());
    }

    public function testGetVerticalAlignmentEnumReturnsStoredValue(): void
    {
        $element = $this->createContentElement();
        $element->VerticalAlignment = VerticalAlignment::Top->value;

        self::assertSame(VerticalAlignment::Top, $element->getVerticalAlignmentEnum());
    }

    public function testGetVerticalAlignmentEnumFallsBackToCenter(): void
    {
        $element = $this->createContentElement();
        $element->VerticalAlignment = '';

        self::assertSame(VerticalAlignment::Center, $element->getVerticalAlignmentEnum());
    }

    public function testGetAspectRatioEnumReturnsStoredValue(): void
    {
        $element = $this->createContentElement();
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        self::assertSame(AspectRatio::SixteenByNine, $element->getAspectRatioEnum());
    }

    public function testGetAspectRatioEnumFallsBackToAuto(): void
    {
        $element = $this->createContentElement();
        $element->MediaRatio = '';

        self::assertSame(AspectRatio::Auto, $element->getAspectRatioEnum());
    }

    public function testHasMediaReturnsFalseWhenNoMediaType(): void
    {
        $element = $this->createContentElement();
        $element->MediaType = '';

        self::assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForImageWithRecordButNoFile(): void
    {
        $element = $this->createContentElement();
        $element->MediaType = 'image';

        // Image DB record without a physical file — exists() returns false
        $image = Image::create();
        $image->Title = 'Test image';
        $image->write();

        $element->MediaImageID = $image->ID;
        $element->write();

        self::assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForImageWithoutImage(): void
    {
        $element = $this->createContentElement();
        $element->MediaType = 'image';
        $element->MediaImageID = 0;

        self::assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsTrueForVideoWithURL(): void
    {
        $element = $this->createContentElement();
        $element->MediaType = 'video';
        $element->VideoURL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        self::assertTrue($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForVideoWithoutURL(): void
    {
        $element = $this->createContentElement();
        $element->MediaType = 'video';
        $element->VideoURL = '';

        self::assertFalse($element->hasMedia());
    }

    public function testHasMediaReturnsFalseForUnrecognisedMediaType(): void
    {
        // A stored Varchar value that does not map to any MediaType case must
        // not crash page rendering. tryFrom() returns null → hasMedia() returns
        // false instead of throwing \ValueError (which MediaType::from would).
        $element = $this->createContentElement();
        $element->MediaType = 'bogus';

        self::assertFalse($element->hasMedia());
    }

    public function testIsLayoutModeRequiresBothColumnsAndMedia(): void
    {
        $element = $this->createContentElement();

        // No columns, no media
        $element->ContentColumns = 0;
        $element->MediaType = '';
        self::assertFalse($element->isLayoutMode());

        // Columns but no media
        $element->ContentColumns = 6;
        $element->MediaType = '';
        self::assertFalse($element->isLayoutMode());

        // Media but no columns
        $element->ContentColumns = 0;
        $element->MediaType = 'video';
        $element->VideoURL = 'https://example.com/video';
        self::assertFalse($element->isLayoutMode());

        // Both
        $element->ContentColumns = 6;
        self::assertTrue($element->isLayoutMode());
    }

    public function testGetLayoutRowClasses(): void
    {
        $element = $this->createContentElement();
        $element->VerticalAlignment = VerticalAlignment::Center->value;

        $classes = $element->getLayoutRowClasses();

        self::assertStringContainsString('grid grid-cols-12', $classes);
        self::assertStringContainsString('items-center', $classes);
    }

    public function testGetMediaColumnClasses(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaPosition = MediaPosition::First->value;

        $classes = $element->getMediaColumnClasses();

        // Exact match kills the !== null guard on getBaseColumnClass() (Tailwind returns null)
        self::assertSame('sm:col-span-6 order-1', $classes);
    }

    public function testGetContentColumnClasses(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaPosition = MediaPosition::First->value;

        $classes = $element->getContentColumnClasses();

        // Exact match kills the !== null guard on getBaseColumnClass() (Tailwind returns null)
        self::assertSame('sm:col-span-6 order-2', $classes);
    }

    public function testGetContentPaddingClassesWithGap(): void
    {
        $element = $this->createContentElement();
        $element->GapSize = 3;
        $element->MediaPosition = MediaPosition::First->value;

        self::assertSame('sm:pl-3', $element->getContentPaddingClasses());
    }

    public function testGetContentPaddingClassesWithoutGap(): void
    {
        $element = $this->createContentElement();
        $element->GapSize = 0;

        self::assertSame('', $element->getContentPaddingClasses());
    }

    public function testGetContentPaddingDirectionReflectsMediaPosition(): void
    {
        $element = $this->createContentElement();
        $element->GapSize = 3;

        $element->MediaPosition = MediaPosition::Last->value;
        self::assertSame('sm:pr-3', $element->getContentPaddingClasses());

        $element->MediaPosition = MediaPosition::First->value;
        self::assertSame('sm:pl-3', $element->getContentPaddingClasses());
    }

    public function testGetMediaRatioClassAutoReturnsNull(): void
    {
        $element = $this->createContentElement();
        $element->MediaRatio = AspectRatio::Auto->value;

        self::assertNull($element->getMediaRatioClass());
    }

    public function testGetMediaRatioClassNonAutoReturnsExpectedClass(): void
    {
        $element = $this->createContentElement();
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        self::assertSame('aspect-video', $element->getMediaRatioClass());
    }

    public function testGetMediaImageWidthReturnsPixelValue(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;

        // 12 total - 6 content = 6 media columns → 768px (1536 * 6/12)
        self::assertSame(768, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthFullWidthWhenNoColumns(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 0;

        // Full grid width → 1536px
        self::assertSame(1536, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthWithOneContentColumn(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 1;

        // 12 - 1 = 11 media columns → round(1536 * 11 / 12) = 1408
        self::assertSame(1408, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthClampsToOneColumnWhenContentEqualsTotal(): void
    {
        // ContentColumns == column count would leave 0 media columns. getColSize()
        // clamps to 1 so the positive-int contract holds and ScaleWidth/Fill never
        // receive a non-positive dimension.
        $element = $this->createContentElement();
        $element->ContentColumns = 12;

        // colSize clamped to 1 → round(1536 * 1 / 12) = 128
        self::assertSame(128, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthClampsToOneColumnWhenContentExceedsTotal(): void
    {
        // ContentColumns > column count would yield a negative span. getColSize()
        // clamps to 1 rather than passing a negative width to the adapter.
        $element = $this->createContentElement();
        $element->ContentColumns = 15;

        // colSize clamped to 1 → round(1536 * 1 / 12) = 128
        self::assertSame(128, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthClampsNegativeContentColumns(): void
    {
        // A negative ContentColumns hits the `$contentColumns <= 0` arm of
        // getColSize(), which returns max(1, columnCount) = the full grid span.
        // Removing that return (ReturnRemoval mutant) would fall through to the
        // `columnCount - contentColumns` branch (12 - (-3) = 15) and produce a
        // different width, so this pins the negative-input clamp.
        $element = $this->createContentElement();
        $element->ContentColumns = -3;

        // Full grid width → round(1536 * 12 / 12) = 1536
        self::assertSame(1536, $element->getMediaImageWidth());
    }

    public function testGetMediaImageHeightSquare(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::Square->value;

        self::assertSame(768, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightSixteenByNine(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        // width 768 → round(768 * 9/16) = 432
        self::assertSame(432, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightSixteenByNineRoundsCorrectly(): void
    {
        // Tailwind's 1536 container divides evenly by 12, so ratio results are exact
        // integers that would not distinguish round() from floor(). Override the
        // container width to a value that yields a fractional result, isolating the
        // rounding behaviour under test.
        Config::modify()->set(TailwindAdapter::class, 'container_max_width', 1000);
        $this->rebuildGridAdapter();

        $element = $this->createContentElement();
        $element->ContentColumns = 3;
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        // mediaColumns=9, width=round(1000*9/12)=750
        // round(750 * 9/16) = round(421.875) = 422 (floor would give 421)
        self::assertSame(422, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightFourByThree(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // width 768 → round(768 * 3/4) = 576
        self::assertSame(576, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightFourByThreeRoundsCorrectly(): void
    {
        // See the 16:9 rounding test: override the evenly-divisible Tailwind
        // container width so the ratio math produces a fractional result.
        Config::modify()->set(TailwindAdapter::class, 'container_max_width', 1000);
        $this->rebuildGridAdapter();

        $element = $this->createContentElement();
        $element->ContentColumns = 7;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // mediaColumns=5, width=round(1000*5/12)=417
        // round(417 * 3/4) = round(312.75) = 313 (floor would give 312)
        self::assertSame(313, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightFourByThreeRoundsDownBelowHalf(): void
    {
        // Counterpart to the test above, on the other side of .5: together they pin
        // round() against both floor() and ceil() for the 4:3 branch.
        Config::modify()->set(TailwindAdapter::class, 'container_max_width', 1006);
        $this->rebuildGridAdapter();

        $element = $this->createContentElement();
        $element->ContentColumns = 7;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // mediaColumns=5, width=round(1006*5/12)=419
        // round(419 * 3/4) = round(314.25) = 314 (ceil would give 315)
        self::assertSame(419, $element->getMediaImageWidth());
        self::assertSame(314, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightAutoWithoutImage(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::Auto->value;
        $element->MediaImageID = 0;

        // No image → falls back to width as height
        self::assertSame(768, $element->getMediaImageHeight());
    }

    public function testGetMediaImageSourceURLReturnsNullWithoutImage(): void
    {
        $element = $this->createContentElement();
        $element->MediaImageID = 0;

        self::assertNull($element->getMediaImageSourceURL());
    }

    public function testGetMediaImageSourceURLReturnsURLWhenImageExists(): void
    {
        // With a real attached image, getMediaImage()->exists() is true so the
        // method falls through the `!$image->exists()` guard and resamples the
        // image, returning a non-empty URL. Pins the early-return-null mutants
        // on the exists() check (a mutated `$image->exists()` to true/false or a
        // removed guard would change this from a real URL to null/crash).
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $this->attachRealImage($element);

        $url = $element->getMediaImageSourceURL();

        self::assertNotNull($url);
        self::assertNotSame('', $url);
    }

    public function testGetMediaImageHeightAutoUsesSourceAspectRatio(): void
    {
        // Auto ratio with a real 200x150 source: height tracks the source aspect
        // ratio, not the width. round(width * sourceHeight / sourceWidth) with a
        // non-square source produces a height distinct from the width, pinning the
        // `!$image->exists()` guard and the dimension arithmetic in
        // getHeightFromSource().
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::Auto->value;
        $this->attachRealImage($element);

        $width = $element->getMediaImageWidth();

        // Source is 200x150 → expected height = round(width * 150 / 200)
        $expectedHeight = (int) round($width * 150 / 200);

        self::assertSame($expectedHeight, $element->getMediaImageHeight());
        self::assertNotSame($width, $element->getMediaImageHeight());
    }

    /**
     * The source image is 200x150, so the derived height is width * 0.75. Both cases below
     * land on a fraction, and they straddle .5 in opposite directions — together they pin
     * round() against both floor() and ceil().
     *
     * @return iterable<string, array{int, int, int}>
     */
    public static function autoHeightRoundingProvider(): iterable
    {
        // 417 * 0.75 = 312.75 → 313 (floor would give 312)
        yield 'fraction above .5 rounds up' => [1000, 417, 313];
        // 419 * 0.75 = 314.25 → 314 (ceil would give 315)
        yield 'fraction below .5 rounds down' => [1006, 419, 314];
    }

    #[DataProvider('autoHeightRoundingProvider')]
    public function testGetMediaImageHeightAutoRoundsTheSourceRatioToNearest(
        int $containerWidth,
        int $expectedWidth,
        int $expectedHeight,
    ): void {
        Config::modify()->set(TailwindAdapter::class, 'container_max_width', $containerWidth);
        $this->rebuildGridAdapter();

        $element = $this->createContentElement();
        $element->ContentColumns = 7;
        $element->MediaRatio = AspectRatio::Auto->value;
        $this->attachRealImage($element);

        self::assertSame($expectedWidth, $element->getMediaImageWidth());
        self::assertSame($expectedHeight, $element->getMediaImageHeight());
    }
    public function testOnBeforeWriteTrimsVideoURL(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = '  https://example.com/video  ';
        $element->write();

        self::assertSame('https://example.com/video', $element->VideoURL);
    }

    public function testOnBeforeWriteDoesNotPopulateEmbedFieldsWhenVideoURLEmpty(): void
    {
        $element = $this->createContentElement();
        $element->VideoURL = '';
        $element->write();

        self::assertSame('', (string) $element->VideoEmbedName);
        self::assertSame('', (string) $element->VideoEmbedURL);
    }

    public function testUpdateCMSFieldsCreatesMediaTab(): void
    {
        $element = $this->createContentElement();
        $fields = $element->getCMSFields();

        self::assertNotNull($fields->findOrMakeTab('Root.Media'));
    }

    public function testUpdateCMSFieldsCreatesLayoutTab(): void
    {
        $element = $this->createContentElement();
        $fields = $element->getCMSFields();

        self::assertNotNull($fields->findOrMakeTab('Root.Layout'));
    }

    public function testUpdateCMSFieldsRemovesScaffoldedFields(): void
    {
        $element = $this->createContentElement();
        $fields = $element->getCMSFields();

        // These fields are removed from Root.Main and not re-added to any other tab
        self::assertNull($fields->dataFieldByName('VideoProvider'));
        self::assertNull($fields->dataFieldByName('VideoHasOverlay'));
        self::assertNull($fields->dataFieldByName('VideoCustomThumbnailID'));
    }

    /**
     * With the default adapter's column_count of 12, getContentColumnOptions
     * produces keys [4..8] → assertArrayHasKey/NotHasKey pins each loop boundary.
     * The ColumnWidthPickerField source also includes 0 (full-width).
     */
    public function testContentColumnsFieldOptionsForDefaultColumnCount(): void
    {
        $element = $this->createContentElement();
        $fields = $element->getCMSFields();

        $field = $fields->dataFieldByName('ContentColumns');
        self::assertNotNull($field);

        /** @var array<int, string> $source */
        $source = $field->getSource();
        $keys = array_keys($source);
        sort($keys);

        // Full-width (0) prepended plus range [4..8] from getContentColumnOptions
        self::assertSame([0, 4, 5, 6, 7, 8], $keys);

        // Label format '%d/%d (content/media)' — kills Minus at 449 ($total-$i → $total+$i)
        self::assertSame('4/8 (content/media)', $source[4]);
        self::assertSame('5/7 (content/media)', $source[5]);
        self::assertSame('8/4 (content/media)', $source[8]);
    }

    public function testContentColumnOptionsReserveTwoMediaColumns(): void
    {
        // With total_columns=9, the content-column loop bound is min(8, total-2)
        // = min(8, 7) = 7, reserving 2 columns for media at the top end. Key 8
        // must NOT appear (that would leave only 1 media column). Pins the
        // `min(8, total - 2)` arithmetic: mutating `- 2` to `+ 2` or `min` to
        // `max` would admit key 8 (or more).
        Config::modify()->set(TailwindAdapter::class, 'total_columns', 9);
        $this->rebuildGridAdapter();

        $element = $this->createContentElement();
        $fields = $element->getCMSFields();

        $field = $fields->dataFieldByName('ContentColumns');
        self::assertNotNull($field);

        /** @var array<int, string> $source */
        $source = $field->getSource();
        $keys = array_keys($source);
        sort($keys);

        // Full-width (0) prepended plus range [4..7] — key 8 reserved out
        self::assertSame([0, 4, 5, 6, 7], $keys);
    }

    public function testGetContentPaddingDirectionForLastOnDesktop(): void
    {
        // Pins the MatchArmRemoval: MediaPosition::LastOnDesktop must share the
        // 'right' branch with MediaPosition::Last.
        $element = $this->createContentElement();
        $element->GapSize = 3;
        $element->MediaPosition = MediaPosition::LastOnDesktop->value;

        self::assertSame('sm:pr-3', $element->getContentPaddingClasses());
    }

    public function testUpdateCMSFieldsShowsVideoEmbedTabWhenEmbedNamePresent(): void
    {
        $element = $this->createContentElement();
        $element->VideoEmbedName = 'A video title';

        $fields = $element->getCMSFields();
        $embedTab = $fields->fieldByName('Root.VideoEmbed');

        // Pins `$embedName !== ''`: if mutated to `===`, tab is never created when name is set
        self::assertNotNull($embedTab);
    }

    public function testUpdateCMSFieldsHidesVideoEmbedTabWhenEmbedNameEmpty(): void
    {
        $element = $this->createContentElement();
        $element->VideoEmbedName = '';

        $fields = $element->getCMSFields();
        $embedTab = $fields->fieldByName('Root.VideoEmbed');

        // Mirror case — mutated `===` would still create the tab when name is empty
        self::assertNull($embedTab);
    }
}
