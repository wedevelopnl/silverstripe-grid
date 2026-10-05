<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Model;

use Override;
use WeDevelop\Grid\Extensions\MediaExtension;
use WeDevelop\MediaField\Form\MediaType;

/**
 * A content element holding a single image or video and nothing else.
 *
 * Extends GridElement directly: it has no HTML body to inherit from
 * ContentElement. The media fields come from MediaExtension, declared here
 * rather than in YAML because the block is meaningless without them.
 */
class MediaElement extends GridElement
{
    private static string $table_name = 'WeDevelop_Grid_MediaElement';

    private static string $singular_name = 'Media';

    private static string $plural_name = 'Media';

    private static string $icon = 'font-icon-block-media';

    private static string $class_description = 'A single image or video';

    /** @var list<class-string> */
    private static array $extensions = [
        MediaExtension::class,
    ];

    /**
     * The caption, else what identifies the media itself: the image's title or
     * the video's embed name. Empty when no media is set.
     */
    #[Override]
    public function getSummary(): ?string
    {
        /** @var string|null $caption */
        $caption = $this->MediaCaption;
        if ($caption !== null && $caption !== '') {
            return $caption;
        }

        /** @var string|null $type */
        $type = $this->MediaType;

        /** @var string|null $label */
        $label = match (MediaType::tryFrom($type ?? '')) {
            MediaType::Image => $this->getComponent('MediaImage')->Title,
            MediaType::Video => $this->VideoEmbedName,
            null => null,
        };

        return $label !== null && $label !== '' ? $label : null;
    }
}
