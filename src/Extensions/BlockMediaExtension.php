<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use LogicException;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use UncleCheese\DisplayLogic\Forms\Wrapper;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Forms\ColumnWidthPickerField;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\MediaField\Form\MediaField;
use WeDevelop\MediaField\Form\MediaType;

/**
 * Adds media (image/video) capability with side-by-side layout to any GridElement.
 *
 * Apply via YAML:
 *   MyElement:
 *     extensions:
 *       - WeDevelop\Grid\Extensions\BlockMediaExtension
 *
 * The element's template must integrate media rendering. Use the provided
 * include `WeDevelop/Grid/Includes/MediaBlock` for the standard layout.
 *
 * @extends Extension<GridElement & static>
 */
class BlockMediaExtension extends Extension
{
    /** @var array<string, string> */
    private static array $db = [
        'ContentColumns' => 'Int',
        'VerticalAlignment' => 'Varchar(20)',
        'GapSize' => 'Int',
        'MediaType' => 'Varchar(5)',
        'MediaCaption' => 'Varchar(255)',
        'MediaRatio' => 'Varchar(10)',
        'MediaPosition' => 'Varchar(20)',
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

    /** @var array<int, string> Named gap size presets (value → label). Configurable via YAML. */
    private static array $gap_sizes = [
        0 => 'None',
        1 => 'Smallest',
        2 => 'Small',
        3 => 'Medium',
        4 => 'Large',
        5 => 'Largest',
    ];

    /** @var array<string, string> */
    private static array $defaults = [
        'MediaPosition' => MediaPosition::First->value,
        'VerticalAlignment' => VerticalAlignment::Center->value,
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

        $mediaType = MediaType::from($type);

        return match ($mediaType) {
            MediaType::Image => $this->getMediaImage()->exists(),
            MediaType::Video => $this->getVideoURL() !== '',
        };
    }

    /** Whether the element should render in side-by-side layout mode. */
    public function isLayoutMode(): bool
    {
        return $this->getContentColumnsValue() > 0 && $this->hasMedia();
    }

    /** Row wrapper classes including vertical alignment. */
    public function getLayoutRowClasses(): string
    {
        $owner = $this->getOwner();
        $classes = [$owner->gridAdapter->getRowClasses()];

        $alignment = $this->getVerticalAlignmentEnum();
        $classes[] = $this->getContentLayoutAdapter()->getVerticalAlignmentClass($alignment);

        return implode(' ', $classes);
    }

    /** Media column classes: base column + width + order. */
    public function getMediaColumnClasses(): string
    {
        $classes = [];

        $baseClass = $this->getContentLayoutAdapter()->getBaseColumnClass();
        if ($baseClass !== null) {
            $classes[] = $baseClass;
        }

        $classes[] = $this->getContentLayoutAdapter()->getMediaWidthClass($this->getContentColumnsValue());
        $classes[] = $this->getContentLayoutAdapter()->getMediaOrderClasses($this->getMediaPositionEnum());

        return implode(' ', $classes);
    }

    /** Content column classes: base column + width + order. */
    public function getContentColumnClasses(): string
    {
        $classes = [];

        $baseClass = $this->getContentLayoutAdapter()->getBaseColumnClass();
        if ($baseClass !== null) {
            $classes[] = $baseClass;
        }

        $classes[] = $this->getContentLayoutAdapter()->getContentWidthClass($this->getContentColumnsValue());
        $classes[] = $this->getContentLayoutAdapter()->getContentOrderClasses($this->getMediaPositionEnum());

        return implode(' ', $classes);
    }

    /** Directional padding class based on media position and gap size. */
    public function getContentPaddingClasses(): string
    {
        $gapSize = $this->getGapSizeValue();

        if ($gapSize <= 0) {
            return '';
        }

        $direction = $this->getContentPaddingDirection();

        return $this->getContentLayoutAdapter()->getPaddingClass($direction, $gapSize);
    }

    /** Aspect ratio CSS class, or null for auto. */
    public function getMediaRatioClass(): ?string
    {
        return $this->getContentLayoutAdapter()->getAspectRatioClass($this->getAspectRatioEnum());
    }

    /** Calculated pixel width for the media image based on column proportion. */
    public function getMediaImageWidth(): int
    {
        return $this->getCalculatedMediaImageWidth();
    }

    /** Calculated pixel height from aspect ratio or source dimensions. */
    public function getMediaImageHeight(): int
    {
        $width = $this->getCalculatedMediaImageWidth();
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

    /** Parse the MediaPosition DB value to its enum. */
    public function getMediaPositionEnum(): MediaPosition
    {
        /** @var string $value */
        $value = $this->getOwner()->MediaPosition ?? '';
        return MediaPosition::tryFrom($value) ?? MediaPosition::First;
    }

    /** Parse the VerticalAlignment DB value to its enum. */
    public function getVerticalAlignmentEnum(): VerticalAlignment
    {
        /** @var string $value */
        $value = $this->getOwner()->VerticalAlignment ?? '';
        return VerticalAlignment::tryFrom($value) ?? VerticalAlignment::Center;
    }

    /** Parse the MediaRatio DB value to its enum. */
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
            'ContentColumns', 'VerticalAlignment', 'GapSize',
            'MediaCaption', 'MediaRatio', 'MediaPosition',
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

        $layoutTab = $fields->findOrMakeTab(
            'Root.Layout',
            _t(self::class . '.LAYOUT_TAB', 'Layout'),
        );

        $totalColumns = $this->getOwner()->gridAdapter->getColumnCount();
        $columnOptions = [0 => _t(self::class . '.FULL_WIDTH', 'Full width (no side-by-side)')]
            + $this->getContentColumnOptions();

        $layoutTab->push(
            ColumnWidthPickerField::create(
                'ContentColumns',
                _t(self::class . '.CONTENT_COLUMNS', 'Content column width'),
                $columnOptions,
                $totalColumns,
            ),
        );

        $mediaPositionField = DropdownField::create(
            'MediaPosition',
            _t(self::class . '.MEDIA_POSITION', 'Media position'),
            $this->getMediaPositionOptions(),
        );
        $mediaPositionField->displayIf('ContentColumns')->isGreaterThan(0);
        $layoutTab->push($mediaPositionField);

        $verticalAlignmentField = DropdownField::create(
            'VerticalAlignment',
            _t(self::class . '.VERTICAL_ALIGNMENT', 'Vertical alignment'),
            $this->getVerticalAlignmentOptions(),
        );
        $verticalAlignmentField->displayIf('ContentColumns')->isGreaterThan(0);
        $layoutTab->push($verticalAlignmentField);

        /** @var array<int, string> $gapSizes */
        $gapSizes = $this->getOwner()->config()->get('gap_sizes');
        $gapSizeField = DropdownField::create(
            'GapSize',
            _t(self::class . '.GAP_SIZE', 'Gap size'),
            $gapSizes,
        );
        $gapSizeField->displayIf('ContentColumns')->isGreaterThan(0);
        $layoutTab->push($gapSizeField);

        // Show video embed data as readonly when available
        /** @var string $embedName */
        $embedName = $this->getOwner()->VideoEmbedName ?? '';

        if ($embedName !== '') {
            $embedTab = $fields->findOrMakeTab(
                'Root.VideoEmbed',
                _t(self::class . '.VIDEO_EMBED_TAB', 'Video Embed'),
            );

            $embedTab->push(ReadonlyField::create('VideoEmbedName', 'Name'));
            $embedTab->push(ReadonlyField::create('VideoEmbedURL', 'URL'));
            $embedTab->push(ReadonlyField::create('VideoEmbedDescription', 'Description'));
            $embedTab->push(ReadonlyField::create('VideoEmbedThumbnail', 'Thumbnail'));
            $embedTab->push(ReadonlyField::create('VideoEmbedCreated', 'Created'));
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
            $this->resolveVideoEmbed($owner);
        }
    }

    /** Resolve oEmbed metadata for the current VideoURL via the MediaField package. */
    protected function resolveVideoEmbed(DataObject $owner): void
    {
        MediaField::saveEmbed(
            $owner,
            videoFullURLField: 'VideoURL',
            videoEmbeddedURLField: 'VideoEmbedURL',
            videoProviderField: 'VideoProvider',
            videoEmbeddedNameField: 'VideoEmbedName',
            videoEmbeddedDescriptionField: 'VideoEmbedDescription',
            videoEmbeddedThumbnailField: 'VideoEmbedThumbnail',
            videoEmbeddedCreatedField: 'VideoEmbedCreated',
        );
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

    /** @return array<string, string> */
    private function getMediaPositionOptions(): array
    {
        return [
            MediaPosition::First->value => _t(self::class . '.POSITION_FIRST', 'Before content'),
            MediaPosition::Last->value => _t(self::class . '.POSITION_LAST', 'After content'),
            MediaPosition::LastOnDesktop->value => _t(self::class . '.POSITION_LAST_DESKTOP', 'After content on desktop'),
        ];
    }

    /** @return array<string, string> */
    private function getVerticalAlignmentOptions(): array
    {
        return [
            VerticalAlignment::Top->value => _t(self::class . '.ALIGN_TOP', 'Top'),
            VerticalAlignment::Center->value => _t(self::class . '.ALIGN_CENTER', 'Center'),
            VerticalAlignment::Bottom->value => _t(self::class . '.ALIGN_BOTTOM', 'Bottom'),
        ];
    }

    /**
     * Content column width options from 4 to 8 (of total grid columns).
     *
     * @return array<int, string>
     */
    private function getContentColumnOptions(): array
    {
        $total = $this->getOwner()->gridAdapter->getColumnCount();
        $options = [];

        for ($i = 4; $i <= min(8, $total - 2); ++$i) {
            $media = $total - $i;
            $options[$i] = sprintf('%d/%d (content/media)', $i, $media);
        }

        return $options;
    }

    private function getCalculatedMediaImageWidth(): int
    {
        /** @var positive-int $colSize */
        $colSize = $this->getColSize();

        return $this->getOwner()->gridAdapter->getColumnPixelWidth($colSize);
    }

    /** Effective column span for the media side. */
    private function getColSize(): int
    {
        $columnCount = $this->getOwner()->gridAdapter->getColumnCount();
        $contentColumns = $this->getContentColumnsValue();

        return $contentColumns > 0
            ? ($columnCount - $contentColumns)
            : $columnCount;
    }

    /** Access the ContentLayoutAdapterInterface through the owner's grid adapter. */
    private function getContentLayoutAdapter(): ContentLayoutAdapterInterface
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

    /**
     * Padding direction based on where the media sits relative to content.
     *
     * @return 'left'|'right'
     */
    private function getContentPaddingDirection(): string
    {
        $position = $this->getMediaPositionEnum();

        return match ($position) {
            MediaPosition::Last, MediaPosition::LastOnDesktop => 'right',
            default => 'left',
        };
    }

    /** Typed accessor for the ContentColumns DB field. */
    private function getContentColumnsValue(): int
    {
        /** @var int $value */
        $value = $this->getOwner()->ContentColumns ?? 0;
        return (int) $value;
    }

    /** Typed accessor for the GapSize DB field. */
    private function getGapSizeValue(): int
    {
        /** @var int $value */
        $value = $this->getOwner()->GapSize ?? 0;
        return (int) $value;
    }

    /** Typed accessor for the VideoURL DB field. */
    private function getVideoURL(): string
    {
        /** @var string $value */
        $value = $this->getOwner()->VideoURL ?? '';
        return (string) $value;
    }

    /** Typed accessor for the MediaImage has_one relation. */
    private function getMediaImage(): Image
    {
        /** @var Image $image */
        $image = $this->getOwner()->MediaImage(); // @phpstan-ignore method.notFound (has_one added by extension)
        return $image;
    }
}
