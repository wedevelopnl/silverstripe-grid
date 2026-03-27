<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Extensions;

use PHPUnit\Framework\Attributes\CoversClass;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\BlockMediaExtension;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
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
        $page = $this->objFromFixture(SiteTree::class, 'test_page');
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

        self::assertStringContainsString('col-md-6', $classes);
        self::assertStringContainsString('order-1', $classes);
    }

    public function testGetContentColumnClasses(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaPosition = MediaPosition::First->value;

        $classes = $element->getContentColumnClasses();

        self::assertStringContainsString('col-md-6', $classes);
        self::assertStringContainsString('order-2', $classes);
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

    public function testGetMediaImageHeightFourByThree(): void
    {
        $element = $this->createContentElement();
        $element->ContentColumns = 6;
        $element->MediaRatio = AspectRatio::FourByThree->value;

        // round(660 * 3/4) = 495
        self::assertSame(495, $element->getMediaImageHeight());
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
}
