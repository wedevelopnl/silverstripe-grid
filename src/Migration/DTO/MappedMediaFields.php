<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

/**
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
