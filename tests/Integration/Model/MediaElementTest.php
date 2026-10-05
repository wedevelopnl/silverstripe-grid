<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Integration\Model;

use Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Extensions\MediaExtension;
use WeDevelop\Grid\Model\MediaElement;
use WeDevelop\Grid\Tests\Integration\Support\DisablesAutoScaffolding;
use WeDevelop\Grid\Tests\Integration\Support\GridTreeFactory;

#[CoversClass(MediaElement::class)]
#[CoversClass(MediaExtension::class)]
final class MediaElementTest extends SapphireTest
{
    use DisablesAutoScaffolding;

    protected static $fixture_file = __DIR__ . '/../Fixture/page.yml';

    private const TEST_IMAGE_PATH = __DIR__ . '/../../E2E/Fixture/assets/test-image.png';

    protected function setUp(): void
    {
        parent::setUp();
        Versioned::set_stage(Versioned::DRAFT);
        $this->disableAutoScaffolding();
        TestAssetStore::activate('MediaElementTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testCarriesTheMediaColumnsWithoutTheSideBySideLayout(): void
    {
        $columns = array_keys(DataObject::getSchema()->databaseFields(MediaElement::class, false));
        sort($columns);

        self::assertSame([
            'ID', 'MediaCaption', 'MediaImageID', 'MediaRatio', 'MediaType',
            'VideoCustomThumbnailID',
            'VideoEmbedCreated', 'VideoEmbedDescription', 'VideoEmbedName', 'VideoEmbedThumbnail', 'VideoEmbedURL',
            'VideoHasOverlay', 'VideoProvider', 'VideoURL',
        ], $columns);
    }

    public function testRendersTheAttachedImage(): void
    {
        $element = $this->createMediaElement();
        $this->attachImage($element, 'Harbour at dusk');

        $html = $element->forTemplate();

        self::assertStringContainsString('<figure class="media-block media-block--image">', $html);
        self::assertStringContainsString(sprintf('src="%s"', $element->getMediaImageSourceURL()), $html);
    }

    public function testRendersNoMediaMarkupWithoutMedia(): void
    {
        $html = $this->createMediaElement()->forTemplate();

        self::assertStringNotContainsString('media-block', $html);
    }

    public function testPublishingTheElementPublishesItsImage(): void
    {
        $this->logInWithPermission('ADMIN');
        $element = $this->createMediaElement();
        $image = $this->attachImage($element, 'Harbour at dusk');

        $element->publishRecursive();

        self::assertTrue($image->isPublished());
    }

    /**
     * @return iterable<string, array{array<string, string>, string|null, string|null}>
     */
    public static function summaryCases(): iterable
    {
        yield 'caption wins over the image title' => [['MediaType' => 'image', 'MediaCaption' => 'Our harbour'], 'Harbour at dusk', 'Our harbour'];
        yield 'image title without a caption' => [['MediaType' => 'image'], 'Harbour at dusk', 'Harbour at dusk'];
        yield 'video embed name without a caption' => [['MediaType' => 'video', 'VideoEmbedName' => 'Launch film'], null, 'Launch film'];
        yield 'image type with no image attached' => [['MediaType' => 'image'], null, null];
        yield 'no media at all' => [[], null, null];
    }

    /**
     * @param array<string, string> $fields
     */
    #[DataProvider('summaryCases')]
    public function testSummaryNamesTheMedia(array $fields, ?string $imageTitle, ?string $expected): void
    {
        $element = $this->createMediaElement();
        $element->update($fields);
        if ($imageTitle !== null) {
            $this->attachImage($element, $imageTitle);
        }

        self::assertSame($expected, $element->getSummary());
    }

    private function createMediaElement(): MediaElement
    {
        $page = $this->objFromFixture(Page::class, 'test_page');
        ['column' => $column] = GridTreeFactory::containerTree($page);

        $element = MediaElement::create();
        $element->ParentID = $column->ID;
        $element->ParentClass = $column::class;
        $element->write();

        return $element;
    }

    private function attachImage(MediaElement $element, string $title): Image
    {
        $image = Image::create();
        $image->setFromLocalFile(self::TEST_IMAGE_PATH, 'media-element-test.png');
        $image->Title = $title;
        $image->write();

        $element->MediaType = 'image';
        $element->MediaImageID = $image->ID;
        $element->write();

        return $image;
    }
}
