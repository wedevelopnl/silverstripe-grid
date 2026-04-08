<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

/**
 * Mapped media field values ready to apply to a ContentElement.
 *
 * Properties use PascalCase to match BlockMediaExtension DB column names.
 */
final readonly class MappedMediaFields
{
    public function __construct(
        public int $ContentColumns,
        public string $VerticalAlignment,
        public int $GapSize,
        public string $MediaType,
        public string $MediaCaption,
        public int $MediaImageID,
        public string $MediaRatio,
        public string $MediaPosition,
        public string $VideoURL,
        public string $VideoProvider,
        public bool $VideoHasOverlay,
        public int $VideoCustomThumbnailID,
        public string $VideoEmbedName,
        public string $VideoEmbedURL,
        public string $VideoEmbedDescription,
        public string $VideoEmbedThumbnail,
        public string $VideoEmbedCreated,
    ) {}

    /**
     * Apply all mapped field values to a target object.
     *
     * Uses dynamic property assignment — the target must accept the field
     * names defined by this DTO (e.g. SilverStripe DataObjects via __set).
     */
    public function applyTo(object $target): void
    {
        assert(\method_exists($target, '__set'), 'Target must support dynamic property assignment (__set)');

        foreach ($this->toArray() as $field => $value) {
            $target->__set($field, $value);
        }
    }

    /**
     * Field name → value pairs keyed by DB column name.
     *
     * @return array{
     *     ContentColumns: int,
     *     VerticalAlignment: string,
     *     GapSize: int,
     *     MediaType: string,
     *     MediaCaption: string,
     *     MediaImageID: int,
     *     MediaRatio: string,
     *     MediaPosition: string,
     *     VideoURL: string,
     *     VideoProvider: string,
     *     VideoHasOverlay: bool,
     *     VideoCustomThumbnailID: int,
     *     VideoEmbedName: string,
     *     VideoEmbedURL: string,
     *     VideoEmbedDescription: string,
     *     VideoEmbedThumbnail: string,
     *     VideoEmbedCreated: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'ContentColumns' => $this->ContentColumns,
            'VerticalAlignment' => $this->VerticalAlignment,
            'GapSize' => $this->GapSize,
            'MediaType' => $this->MediaType,
            'MediaCaption' => $this->MediaCaption,
            'MediaImageID' => $this->MediaImageID,
            'MediaRatio' => $this->MediaRatio,
            'MediaPosition' => $this->MediaPosition,
            'VideoURL' => $this->VideoURL,
            'VideoProvider' => $this->VideoProvider,
            'VideoHasOverlay' => $this->VideoHasOverlay,
            'VideoCustomThumbnailID' => $this->VideoCustomThumbnailID,
            'VideoEmbedName' => $this->VideoEmbedName,
            'VideoEmbedURL' => $this->VideoEmbedURL,
            'VideoEmbedDescription' => $this->VideoEmbedDescription,
            'VideoEmbedThumbnail' => $this->VideoEmbedThumbnail,
            'VideoEmbedCreated' => $this->VideoEmbedCreated,
        ];
    }
}
