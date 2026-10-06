<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Extensions;

use Override;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use WeDevelop\Grid\Forms\ColumnWidthPickerField;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Adds media (image/video) with a side-by-side layout to any GridElement.
 *
 * Apply via YAML:
 *   MyElement:
 *     extensions:
 *       - WeDevelop\Grid\Extensions\BlockMediaExtension
 *
 * The media itself comes from {@see MediaExtension}; this subclass adds the
 * layout that sets it beside the element's own content. SilverStripe merges an
 * extension's statics along its ancestry, so the owner gets both column sets —
 * apply only this one, never both.
 *
 * The element's template must integrate media rendering. Use the provided
 * include `WeDevelop/Grid/Includes/MediaBlock` for the standard layout.
 */
class BlockMediaExtension extends MediaExtension
{
    /** @var array<string, string> */
    private static array $db = [
        'ContentColumns' => 'Int',
        // Enum members mirror the VerticalAlignment value enum exactly; the
        // DatabaseEnumParityTest guards against drift between the two.
        'VerticalAlignment' => "Enum('top,center,bottom', 'center')",
        'GapSize' => 'Int',
        // Members mirror the MediaPosition value enum exactly (see parity test).
        'MediaPosition' => "Enum('first,last,last-on-desktop', 'first')",
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
    ];

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

    public function getMediaPositionEnum(): MediaPosition
    {
        /** @var string $value */
        $value = $this->getOwner()->MediaPosition ?? '';
        return MediaPosition::tryFrom($value) ?? MediaPosition::First;
    }

    public function getVerticalAlignmentEnum(): VerticalAlignment
    {
        /** @var string $value */
        $value = $this->getOwner()->VerticalAlignment ?? '';
        return VerticalAlignment::tryFrom($value) ?? VerticalAlignment::Center;
    }

    #[Override]
    public function updateCMSFields(FieldList $fields): void
    {
        parent::updateCMSFields($fields);

        $fields->removeByName(['ContentColumns', 'VerticalAlignment', 'GapSize', 'MediaPosition']);

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
    }

    /**
     * Effective column span for the media side.
     *
     * Clamped to at least 1: when ContentColumns >= the grid column count the
     * raw difference would be 0 or negative, which violates the positive-int
     * contract expected by getColumnPixelWidth()/Fill()/ScaleWidth().
     *
     * @return positive-int
     */
    #[Override]
    protected function getMediaColumnSpan(): int
    {
        $columnCount = parent::getMediaColumnSpan();
        $contentColumns = $this->getContentColumnsValue();

        if ($contentColumns <= 0) {
            return $columnCount;
        }

        return max(1, $columnCount - $contentColumns);
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
     * Content column width options from 8 down to 4 (of total grid columns),
     * so the options run from the narrowest media column to the widest.
     *
     * @return array<int, string>
     */
    private function getContentColumnOptions(): array
    {
        $total = $this->getOwner()->gridAdapter->getColumnCount();
        $options = [];

        for ($i = min(8, $total - 2); $i >= 4; --$i) {
            $media = $total - $i;
            // Media first, so the ratio reads in the same order as the option's
            // diagram. The stored value stays the content width.
            $options[$i] = _t(
                self::class . '.SPLIT_RATIO',
                '{media}/{content} (media/content)',
                ['media' => $media, 'content' => $i],
            );
        }

        return $options;
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

    private function getContentColumnsValue(): int
    {
        /** @var int $value */
        $value = $this->getOwner()->ContentColumns ?? 0;
        return (int) $value;
    }

    private function getGapSizeValue(): int
    {
        /** @var int $value */
        $value = $this->getOwner()->GapSize ?? 0;
        return (int) $value;
    }
}
