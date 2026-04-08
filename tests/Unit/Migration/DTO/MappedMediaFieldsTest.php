<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Migration\DTO;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Migration\DTO\MappedMediaFields;

#[CoversClass(MappedMediaFields::class)]
final class MappedMediaFieldsTest extends TestCase
{
    private function createDistinctFields(): MappedMediaFields
    {
        return new MappedMediaFields(
            ContentColumns: 6,
            VerticalAlignment: 'center',
            GapSize: 2,
            MediaType: 'image',
            MediaCaption: 'Test caption',
            MediaImageID: 42,
            MediaRatio: '16x9',
            MediaPosition: 'first',
            VideoURL: 'https://example.com/video',
            VideoProvider: 'youtube',
            VideoHasOverlay: true,
            VideoCustomThumbnailID: 99,
            VideoEmbedName: 'Embed Name',
            VideoEmbedURL: 'https://embed.example.com',
            VideoEmbedDescription: 'Embed Description',
            VideoEmbedThumbnail: 'https://thumb.example.com',
            VideoEmbedCreated: '2024-01-15',
        );
    }

    public function testToArrayReturnsAllFieldsWithCorrectValues(): void
    {
        $fields = $this->createDistinctFields();
        $array = $fields->toArray();

        self::assertCount(17, $array);
        self::assertSame(6, $array['ContentColumns']);
        self::assertSame('center', $array['VerticalAlignment']);
        self::assertSame(2, $array['GapSize']);
        self::assertSame('image', $array['MediaType']);
        self::assertSame('Test caption', $array['MediaCaption']);
        self::assertSame(42, $array['MediaImageID']);
        self::assertSame('16x9', $array['MediaRatio']);
        self::assertSame('first', $array['MediaPosition']);
        self::assertSame('https://example.com/video', $array['VideoURL']);
        self::assertSame('youtube', $array['VideoProvider']);
        self::assertTrue($array['VideoHasOverlay']);
        self::assertSame(99, $array['VideoCustomThumbnailID']);
        self::assertSame('Embed Name', $array['VideoEmbedName']);
        self::assertSame('https://embed.example.com', $array['VideoEmbedURL']);
        self::assertSame('Embed Description', $array['VideoEmbedDescription']);
        self::assertSame('https://thumb.example.com', $array['VideoEmbedThumbnail']);
        self::assertSame('2024-01-15', $array['VideoEmbedCreated']);
    }

    public function testApplyToSetsAllFieldsOnTarget(): void
    {
        $target = new class () {
            /** @var array<string, mixed> */
            public array $fields = [];

            public function __set(string $name, mixed $value): void
            {
                $this->fields[$name] = $value;
            }
        };

        $fields = $this->createDistinctFields();
        $fields->applyTo($target);

        self::assertCount(17, $target->fields);
        self::assertSame(6, $target->fields['ContentColumns']);
        self::assertSame('center', $target->fields['VerticalAlignment']);
        self::assertSame(2, $target->fields['GapSize']);
        self::assertSame('image', $target->fields['MediaType']);
        self::assertSame('Test caption', $target->fields['MediaCaption']);
        self::assertSame(42, $target->fields['MediaImageID']);
        self::assertSame('16x9', $target->fields['MediaRatio']);
        self::assertSame('first', $target->fields['MediaPosition']);
        self::assertSame('https://example.com/video', $target->fields['VideoURL']);
        self::assertSame('youtube', $target->fields['VideoProvider']);
        self::assertTrue($target->fields['VideoHasOverlay']);
        self::assertSame(99, $target->fields['VideoCustomThumbnailID']);
        self::assertSame('Embed Name', $target->fields['VideoEmbedName']);
        self::assertSame('https://embed.example.com', $target->fields['VideoEmbedURL']);
        self::assertSame('Embed Description', $target->fields['VideoEmbedDescription']);
        self::assertSame('https://thumb.example.com', $target->fields['VideoEmbedThumbnail']);
        self::assertSame('2024-01-15', $target->fields['VideoEmbedCreated']);
    }
}
