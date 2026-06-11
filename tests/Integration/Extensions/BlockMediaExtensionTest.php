<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use Page;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\RecordingBlockMediaExtension;
use WeDevelop\Grid\Tests\Integration\Extensions\Support\ThrowingBlockMediaExtension;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

#[CoversClass(BlockMediaExtension::class)]
final class BlockMediaExtensionTest extends SapphireTest
{
    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);
    }

    private function createContentElement(): ContentElement
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        $section = GridTreeFactory::section($page);
        $row = GridTreeFactory::row($section);
        $column = GridTreeFactory::column($row);

        return GridTreeFactory::contentElement($column, title: 'Media Test');
    }

    // ── Enum parsers ────────────────────────────────────────────

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

    // ── hasMedia ────────────────────────────────────────────────

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

    // ── isLayoutMode ────────────────────────────────────────────

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

    // ── CSS class methods ───────────────────────────────────────

    public function testGetLayoutRowClasses(): void
    {
        $element = $this->createContentElement();
        $element->VerticalAlignment = VerticalAlignment::Center->value;

        $classes = $element->getLayoutRowClasses();

        self::assertStringContainsString('row', $classes);
        self::assertStringContainsString('align-items-center', $classes);
    }

    public function testGetMediaColumnClasses(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaPosition = MediaPosition::First->value;

        $classes = $element->getMediaColumnClasses();

        // Exact match kills the !== null guard on getBaseColumnClass() (Bootstrap returns null)
        self::assertSame('col-md-6 order-1', $classes);
    }

    public function testGetContentColumnClasses(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaPosition = MediaPosition::First->value;

        $classes = $element->getContentColumnClasses();

        // Exact match kills the !== null guard on getBaseColumnClass() (Bootstrap returns null)
        self::assertSame('col-md-6 order-2', $classes);
    }

    public function testGetContentPaddingClassesWithGap(): void
    {
        $element = $this->createContentElement();
        $element->GapSize = 3;
        $element->MediaPosition = MediaPosition::First->value;

        self::assertSame('ps-md-3', $element->getContentPaddingClasses());
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
        self::assertSame('pe-md-3', $element->getContentPaddingClasses());

        $element->MediaPosition = MediaPosition::First->value;
        self::assertSame('ps-md-3', $element->getContentPaddingClasses());
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

        self::assertSame('ratio ratio-16x9', $element->getMediaRatioClass());
    }

    // ── Image dimensions ────────────────────────────────────────

    public function testGetMediaImageWidthReturnsPixelValue(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;

        // 12 total - 6 content = 6 media columns → 660px (1320 * 6/12)
        self::assertSame(660, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthFullWidthWhenNoColumns(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 0;

        // Full grid width → 1320px
        self::assertSame(1320, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthWithOneContentColumn(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 1;

        // 12 - 1 = 11 media columns → round(1320 * 11 / 12) = 1210
        self::assertSame(1210, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthClampsToOneColumnWhenContentEqualsTotal(): void
    {
        // ContentColumns == column count would leave 0 media columns. getColSize()
        // clamps to 1 so the positive-int contract holds and ScaleWidth/Fill never
        // receive a non-positive dimension.
        $element = $this->createContentElement();
        $element->ContentColumns = 12;

        // colSize clamped to 1 → round(1320 * 1 / 12) = 110
        self::assertSame(110, $element->getMediaImageWidth());
    }

    public function testGetMediaImageWidthClampsToOneColumnWhenContentExceedsTotal(): void
    {
        // ContentColumns > column count would yield a negative span. getColSize()
        // clamps to 1 rather than passing a negative width to the adapter.
        $element = $this->createContentElement();
        $element->ContentColumns = 15;

        // colSize clamped to 1 → round(1320 * 1 / 12) = 110
        self::assertSame(110, $element->getMediaImageWidth());
    }

    public function testGetMediaImageHeightSquare(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::Square->value;

        self::assertSame(660, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightSixteenByNine(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        // round(660 * 9/16) = 371
        self::assertSame(371, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightSixteenByNineRoundsCorrectly(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 3;
        $element->MediaRatio = AspectRatio::SixteenByNine->value;

        // mediaColumns=9, width=round(1320*9/12)=990
        // round(990 * 9/16) = round(556.875) = 557 (floor would give 556)
        self::assertSame(557, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightFourByThree(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // round(660 * 3/4) = 495
        self::assertSame(495, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightFourByThreeRoundsCorrectly(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 5;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // mediaColumns=7, width=round(1320*7/12)=770
        // round(770 * 3/4) = round(577.5) = 578 (floor would give 577)
        self::assertSame(578, $element->getMediaImageHeight());
    }

    public function testGetMediaImageHeightAutoWithoutImage(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::Auto->value;
        $element->MediaImageID = 0;

        // No image → falls back to width as height
        self::assertSame(660, $element->getMediaImageHeight());
    }

    // ── getMediaImageSourceURL ──────────────────────────────────

    public function testGetMediaImageSourceURLReturnsNullWithoutImage(): void
    {
        $element = $this->createContentElement();
        $element->MediaImageID = 0;

        self::assertNull($element->getMediaImageSourceURL());
    }

    // ── onBeforeWrite ───────────────────────────────────────────

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

    // ── updateCMSFields ─────────────────────────────────────────

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

    // ── ContentColumns field options (loop bounds) ──────────────────────────

    /**
     * With the default Bootstrap adapter (column_count=12), getContentColumnOptions
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

    // ── getContentPaddingDirection: both Last and LastOnDesktop → right ─────

    public function testGetContentPaddingDirectionForLastOnDesktop(): void
    {
        // Pins the MatchArmRemoval: MediaPosition::LastOnDesktop must share the
        // 'right' branch with MediaPosition::Last.
        $element = $this->createContentElement();
        $element->GapSize = 3;
        $element->MediaPosition = MediaPosition::LastOnDesktop->value;

        self::assertSame('pe-md-3', $element->getContentPaddingClasses());
    }

    // ── updateCMSFields VideoEmbed tab visibility ───────────────────────────

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

    // ── onBeforeWrite resolveVideoEmbed trigger conditions ──────────────────

    /**
     * Swap in a test-only subclass of BlockMediaExtension that replaces
     * resolveVideoEmbed() with a call counter. Lets us pin the
     * `$changed && $videoUrl !== ''` guard without hitting the real oEmbed
     * network path (which MediaField::saveEmbed invokes).
     */
    private function swapInRecordingExtension(): void
    {
        Config::modify()->remove(ContentElement::class, 'extensions', BlockMediaExtension::class);
        Config::modify()->merge(ContentElement::class, 'extensions', [RecordingBlockMediaExtension::class]);
        RecordingBlockMediaExtension::reset();
    }

    public function testOnBeforeWriteResolvesEmbedWhenURLChangedAndNonEmpty(): void
    {
        $this->swapInRecordingExtension();

        $element = $this->createContentElement();
        $element->write();
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls, 'Initial write with empty URL must not resolve');

        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        // Pins `$changed && $videoUrl !== ''`: both sub-expressions must be true
        self::assertSame(1, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenURLUnchanged(): void
    {
        $this->swapInRecordingExtension();

        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        RecordingBlockMediaExtension::reset();
        $element->Title = 'Updated title';
        $element->write();

        // `$changed` is false → guard short-circuits; mutated LogicalAnd `||` would wrongly call
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenURLChangedToEmpty(): void
    {
        $this->swapInRecordingExtension();

        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=abc';
        $element->write();

        RecordingBlockMediaExtension::reset();
        $element->VideoURL = '';
        $element->write();

        // `$videoUrl !== ''` is false → guard short-circuits; any mutation replacing `!==` with
        // `===` or flipping the && would call resolve here
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }

    public function testOnBeforeWriteSkipsResolveWhenUnchangedAndEmpty(): void
    {
        $this->swapInRecordingExtension();

        $element = $this->createContentElement();
        $element->VideoURL = '';
        $element->write();
        RecordingBlockMediaExtension::reset();

        $element->Title = 'Something';
        $element->write();

        // Both sub-expressions false — LogicalAndAllSubExprNegation flips to `!$changed && !(url!=='')`
        // which would be true here and call resolve
        self::assertSame(0, RecordingBlockMediaExtension::$resolveCalls);
    }

    /**
     * Swap in a test-only subclass whose resolveVideoEmbed() always throws,
     * simulating a transient oEmbed network failure without touching the
     * network. The throw must be caught inside onBeforeWrite so the save
     * still completes.
     */
    private function swapInThrowingExtension(): void
    {
        Config::modify()->remove(ContentElement::class, 'extensions', BlockMediaExtension::class);
        Config::modify()->merge(ContentElement::class, 'extensions', [ThrowingBlockMediaExtension::class]);
    }

    public function testOnBeforeWriteSucceedsWhenEmbedResolutionThrows(): void
    {
        $this->swapInThrowingExtension();

        $element = $this->createContentElement();
        $element->VideoURL = 'https://youtube.com/watch?v=throws';
        $element->write();

        // A throwing embed resolver must not abort the save: the record persists
        // (gets an ID) and the trimmed URL is stored, just without embed metadata.
        self::assertGreaterThan(0, (int) $element->ID);
        self::assertSame('https://youtube.com/watch?v=throws', $element->VideoURL);
        self::assertSame('', (string) $element->VideoEmbedName);
    }
}
