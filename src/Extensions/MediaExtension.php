<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use Embed\Embed;
use LogicException;
use Psr\Log\LoggerInterface;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use UncleCheese\DisplayLogic\Forms\Wrapper;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\MediaField\Form\MediaField;
use WeDevelop\MediaField\Form\MediaType;
use Throwable;

/**
 * Adds a single image or video (with caption and aspect ratio) to any GridElement.
 *
 * Apply via YAML:
 *   MyElement:
 *     extensions:
 *       - WeDevelop\Grid\Extensions\MediaExtension
 *
 * Render it with the include `WeDevelop/Grid/Includes/MediaBlock`. For media
 * placed side by side with an element's own content, apply
 * {@see BlockMediaExtension} instead — it adds the layout controls on top.
 *
 * @extends Extension<GridElement & static>
 */
class MediaExtension extends Extension
{
    /** @var array<string, string> */
    private static array $db = [
        // MediaType stays a Varchar, not an Enum: '' (no media selected) is a
        // meaningful tri-state value that getHasMedia() relies on, so the column
        // is {image, video, ∅} — not the two-case MediaField enum alone.
        'MediaType' => 'Varchar(5)',
        'MediaCaption' => 'Varchar(255)',
        // Members mirror the AspectRatio value enum exactly (see parity test).
        'MediaRatio' => "Enum('auto,1x1,4x3,16x9', 'auto')",
        'VideoURL' => 'Varchar(512)',
        'VideoProvider' => 'Varchar(100)',
        'VideoHasOverlay' => 'Boolean(false)',
        'VideoEmbedName' => 'Varchar(255)',
        'VideoEmbedURL' => 'Varchar(255)',
        'VideoEmbedDescription' => 'Text',
        'VideoEmbedThumbnail' => 'Varchar(255)',
        'VideoEmbedCreated' => 'Varchar(255)',
    ];

    /** @var array<string, class-string> */
    private static array $has_one = [
        'MediaImage' => Image::class,
        'VideoCustomThumbnail' => Image::class,
    ];

    /** @var list<string> */
    private static array $owns = [
        'MediaImage',
        'VideoCustomThumbnail',
    ];

    /** @var list<string> */
    private static array $cascade_deletes = [
        'MediaImage',
        'VideoCustomThumbnail',
    ];

    /** @var list<string> */
    private static array $cascade_duplicates = [
        'MediaImage',
        'VideoCustomThumbnail',
    ];

    /** @var array<string, string> */
    private static array $defaults = [
        'MediaRatio' => AspectRatio::Auto->value,
    ];

    /** Whether an image is attached or a video URL is present. */
    public function hasMedia(): bool
    {
        $owner = $this->getOwner();

        /** @var string|null $type */
        $type = $owner->MediaType;
        if ($type === '' || $type === null) {
            return false;
        }

        $mediaType = MediaType::tryFrom($type);
        if ($mediaType === null) {
            return false;
        }

        return match ($mediaType) {
            MediaType::Image => $this->getMediaImage()->exists(),
            MediaType::Video => $this->getVideoURL() !== '',
        };
    }

    /** Aspect ratio CSS class, or null for auto. */
    public function getMediaRatioClass(): ?string
    {
        return $this->getContentLayoutAdapter()->getAspectRatioClass($this->getAspectRatioEnum());
    }

    /** Calculated pixel width for the media image based on column proportion. */
    public function getMediaImageWidth(): int
    {
        return $this->getOwner()->gridAdapter->getColumnPixelWidth($this->getMediaColumnSpan());
    }

    /** Calculated pixel height from aspect ratio or source dimensions. */
    public function getMediaImageHeight(): int
    {
        $width = $this->getMediaImageWidth();
        $ratio = $this->getAspectRatioEnum();

        return match ($ratio) {
            AspectRatio::Square => $width,
            AspectRatio::FourByThree => (int) round($width * 3 / 4),
            AspectRatio::SixteenByNine => (int) round($width * 9 / 16),
            AspectRatio::Auto => $this->getHeightFromSource($width),
        };
    }

    /**
     * Resized image URL using Fill (with aspect ratio) or ScaleWidth (auto).
     * Null when no image is attached.
     */
    public function getMediaImageSourceURL(): ?string
    {
        $image = $this->getMediaImage();

        if (!$image->exists()) {
            return null;
        }

        $width = $this->getMediaImageWidth();
        $height = $this->getMediaImageHeight();
        $ratio = $this->getAspectRatioEnum();

        $resized = $ratio === AspectRatio::Auto
            ? $image->ScaleWidth($width)
            : $image->Fill($width, $height);

        if ($resized === null) {
            return null;
        }

        return $resized->getURL();
    }

    public function getAspectRatioEnum(): AspectRatio
    {
        /** @var string $value */
        $value = $this->getOwner()->MediaRatio ?? '';
        return AspectRatio::tryFrom($value) ?? AspectRatio::Auto;
    }

    public function updateCMSFields(FieldList $fields): void
    {
        // Remove scaffolded fields — MediaField will re-create type/image/video fields
        $fields->removeByName([
            'MediaCaption', 'MediaRatio',
            'VideoProvider', 'VideoHasOverlay',
            'VideoEmbedName', 'VideoEmbedURL', 'VideoEmbedDescription',
            'VideoEmbedThumbnail', 'VideoEmbedCreated',
            'VideoCustomThumbnailID', 'VideoCustomThumbnail',
        ]);

        $mediaTab = $fields->findOrMakeTab(
            'Root.Media',
            _t(self::class . '.MEDIA_TAB', 'Media'),
        );

        // MediaField manages MediaType dropdown, MediaImage upload, and VideoURL field
        $mediaField = MediaField::create(
            $fields,
            'MediaUploads',
            'MediaType',
            'MediaImage',
            'VideoURL',
        );
        $mediaTab->push($mediaField);

        $thumbnailWrapper = Wrapper::create(
            UploadField::create(
                'VideoCustomThumbnail',
                _t(self::class . '.CUSTOM_VIDEO_THUMBNAIL', 'Custom video thumbnail'),
            )
                ->setFolderName('MediaUploads')
                ->setDescription(_t(
                    self::class . '.OVERWRITES_DEFAULT_THUMBNAIL',
                    'This overwrites the default thumbnail provided by the video platform',
                )),
        );
        $thumbnailWrapper->displayIf('MediaType')->isEqualTo(MediaType::Video->value);
        $mediaTab->push($thumbnailWrapper);

        $mediaTab->push(
            TextField::create(
                'MediaCaption',
                _t(self::class . '.MEDIA_CAPTION', 'Caption'),
            ),
        );

        $mediaTab->push(
            DropdownField::create(
                'MediaRatio',
                _t(self::class . '.MEDIA_RATIO', 'Aspect ratio'),
                $this->getAspectRatioOptions(),
            ),
        );

        // Show video embed data as readonly when available
        /** @var string $embedName */
        $embedName = $this->getOwner()->VideoEmbedName ?? '';

        if ($embedName !== '') {
            $embedTab = $fields->findOrMakeTab(
                'Root.VideoEmbed',
                _t(self::class . '.VIDEO_EMBED_TAB', 'Video Embed'),
            );

            $embedTab->push(ReadonlyField::create('VideoEmbedName', _t(self::class . '.VIDEO_EMBED_NAME', 'Name')));
            $embedTab->push(ReadonlyField::create('VideoEmbedURL', _t(self::class . '.VIDEO_EMBED_URL', 'URL')));
            $embedTab->push(ReadonlyField::create('VideoEmbedDescription', _t(self::class . '.VIDEO_EMBED_DESCRIPTION', 'Description')));
            $embedTab->push(ReadonlyField::create('VideoEmbedThumbnail', _t(self::class . '.VIDEO_EMBED_THUMBNAIL', 'Thumbnail')));
            $embedTab->push(ReadonlyField::create('VideoEmbedCreated', _t(self::class . '.VIDEO_EMBED_CREATED', 'Created')));
        }
    }

    public function onBeforeWrite(): void
    {
        $owner = $this->getOwner();

        $videoUrl = trim($this->getVideoURL());
        $owner->VideoURL = $videoUrl;
        /** @var bool $changed */
        $changed = $owner->isChanged('VideoURL', DataObject::CHANGE_VALUE);
        if ($changed && $videoUrl !== '') {
            // resolveVideoEmbed performs a synchronous oEmbed HTTP fetch. A
            // transient network failure or a malformed-but-non-empty URL must
            // not abort the whole save — log the failure (message only, the URL
            // may carry untrusted data) and let the write proceed without embed
            // metadata.
            try {
                $this->resolveVideoEmbed($owner);
            } catch (Throwable $exception) {
                // Extensions cannot use $dependencies (framework instantiates
                // them without DI args) — resolve the logger from the container.
                Injector::inst()->get(LoggerInterface::class)->warning(
                    sprintf('MediaExtension failed to resolve video embed: %s', $exception->getMessage()),
                );
            }
        }
    }

    /** Resolve oEmbed metadata for the current VideoURL via the MediaField package. */
    protected function resolveVideoEmbed(DataObject $owner): void
    {
        MediaField::saveEmbed(
            $owner,
            new Embed(),
            videoFullURLField: 'VideoURL',
            videoEmbeddedURLField: 'VideoEmbedURL',
            videoProviderField: 'VideoProvider',
            videoEmbeddedNameField: 'VideoEmbedName',
            videoEmbeddedDescriptionField: 'VideoEmbedDescription',
            videoEmbeddedThumbnailField: 'VideoEmbedThumbnail',
            videoEmbeddedCreatedField: 'VideoEmbedCreated',
        );
    }

    /**
     * How many grid columns the media spans, which sizes the served image.
     *
     * The full grid width: an element cannot see its containing Column's width,
     * so the image is sized for the widest place it could be rendered.
     *
     * @return positive-int
     */
    protected function getMediaColumnSpan(): int
    {
        return $this->getOwner()->gridAdapter->getColumnCount();
    }

    /** Access the ContentLayoutAdapterInterface through the owner's grid adapter. */
    protected function getContentLayoutAdapter(): ContentLayoutAdapterInterface
    {
        $adapter = $this->getOwner()->gridAdapter;
        if (!$adapter instanceof ContentLayoutAdapterInterface) {
            throw new LogicException(sprintf(
                'Configured grid adapter %s must implement %s. Bind the interface in _config/content-layout.yml.',
                $adapter::class,
                ContentLayoutAdapterInterface::class,
            ));
        }

        return $adapter;
    }

    protected function getMediaImage(): Image
    {
        /** @var Image $image */
        $image = $this->getOwner()->MediaImage(); // @phpstan-ignore method.notFound (has_one added by extension)
        return $image;
    }

    /** @return array<string, string> */
    private function getAspectRatioOptions(): array
    {
        return [
            AspectRatio::Auto->value => _t(self::class . '.RATIO_AUTO', 'Auto'),
            AspectRatio::Square->value => _t(self::class . '.RATIO_1X1', 'Square (1:1)'),
            AspectRatio::FourByThree->value => _t(self::class . '.RATIO_4X3', '4:3'),
            AspectRatio::SixteenByNine->value => _t(self::class . '.RATIO_16X9', '16:9'),
        ];
    }

    /** Calculate height from source image dimensions, preserving aspect ratio. */
    private function getHeightFromSource(int $targetWidth): int
    {
        $image = $this->getMediaImage();

        if (!$image->exists()) {
            return $targetWidth;
        }

        $sourceWidth = $image->getWidth();
        $sourceHeight = $image->getHeight();

        if ($sourceWidth <= 0) {
            return $targetWidth;
        }

        return (int) round($targetWidth * $sourceHeight / $sourceWidth);
    }

    private function getVideoURL(): string
    {
        /** @var string $value */
        $value = $this->getOwner()->VideoURL ?? '';
        return (string) $value;
    }
}
