<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use SilverStripe\Core\Config\Configurable;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\VerticalAlignment;
use WeDevelop\Grid\Value\Viewport;

/**
 * Configuration-driven grid adapter that translates layout intent into CSS classes.
 *
 * All framework-specific CSS knowledge is expressed as Configurable static
 * properties — format strings, class maps, and scalar values. This class
 * contains only shared logic: sprintf formatting, enum lookups, and config
 * validation.
 *
 * Concrete framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter)
 * extend this class with zero methods — they only override the static properties
 * to declare their CSS vocabulary. Projects can override any property via YAML.
 *
 * @see BootstrapAdapter for a complete preset example
 */
class GridAdapter implements GridAdapterInterface, ContentLayoutAdapterInterface
{
    use Configurable;

    // ─── Grid topology ──────────────────────────────────────────────

    /** @var array<string, string> Viewport key → human label, ordered small→large */
    private static array $viewport_definitions = [];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1320;

    private static string $default_viewport = '';

    /** @var list<string>|null Restrict active viewports; null = all */
    private static ?array $enabled_viewports = null;

    // ─── Width & offset formats ─────────────────────────────────────

    /** Viewport key that uses base (no-infix) format; null if all viewports use responsive format */
    private static ?string $base_viewport_key = null;

    /** sprintf: %d = width */
    private static string $base_width_format = '';

    /** sprintf: %1$s = viewport, %2$d = width */
    private static string $responsive_width_format = '';

    /** sprintf: %d = offset */
    private static string $base_offset_format = '';

    /** sprintf: %1$s = viewport, %2$d = offset (after adjustment) */
    private static string $responsive_offset_format = '';

    /** Added to offset before formatting (0 for margin-based, 1 for Tailwind col-start) */
    private static int $offset_adjustment = 0;

    // ─── Visibility formats ─────────────────────────────────────────

    /** Hide class for the base viewport (literal string, no sprintf) */
    private static string $base_hide_class = '';

    /** sprintf: %s = viewport key */
    private static string $responsive_hide_format = '';

    /** sprintf: %s = viewport key */
    private static string $responsive_restore_format = '';

    // ─── Container & structure ───────────────────────────────────────

    /** sprintf: %d = column count (extra args ignored for static formats like 'row') */
    private static string $row_class_format = '';

    private static string $container_class = '';

    private static string $fluid_container_class = '';

    /** @var array<string, string> CSS class → human label */
    private static array $title_class_options = [];

    /** OffsetStrategy enum value: 'margin' or 'grid-placement' */
    private static string $offset_strategy = 'margin';

    // ─── Content layout ─────────────────────────────────────────────

    /** @var array<string, ?string> AspectRatio value → CSS class (null for Auto) */
    private static array $aspect_ratio_classes = [];

    /** @var array<string, string> VerticalAlignment value → CSS class */
    private static array $vertical_alignment_classes = [];

    /** sprintf: %d = position (1 or 2) */
    private static string $order_class_format = '';

    /** sprintf: %1$s = viewport, %2$d = position */
    private static string $responsive_order_format = '';

    /** @var array<string, string> 'left'|'right' → CSS direction prefix */
    private static array $padding_direction_map = [];

    /** sprintf: %1$s = direction prefix, %2$s = viewport, %3$d = size */
    private static string $padding_format = '';

    /** Framework base column class (e.g. Bulma's 'column'); null if not needed */
    private static ?string $base_column_class = null;

    // ─── Resolved instance state (validated/transformed in constructor) ──

    /** @var array<string, Viewport> */
    private readonly array $viewports;

    /** @var positive-int */
    private readonly int $columnCount;

    /** @var positive-int */
    private readonly int $containerMaxWidth;

    private readonly Viewport $defaultViewport;

    public function __construct()
    {
        // Build viewport objects from config
        /** @var array<non-empty-string, non-empty-string> $viewportDefs */
        $viewportDefs = static::config()->get('viewport_definitions');
        $allViewports = [];

        foreach ($viewportDefs as $key => $label) {
            $allViewports[$key] = new Viewport($key, $label);
        }

        // Validate and resolve topology
        $this->viewports = $this->applyViewportFilter($allViewports);
        $this->columnCount = $this->resolveColumnCount();
        $this->containerMaxWidth = $this->resolveContainerMaxWidth();
        $this->defaultViewport = $this->resolveDefaultViewport();
    }

    // ─── GridAdapterInterface: grid topology ────────────────────────

    /** @return list<Viewport> */
    public function getViewports(): array
    {
        return array_values($this->viewports);
    }

    /** @return positive-int */
    public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    public function getDefaultViewport(): Viewport
    {
        return $this->defaultViewport;
    }

    /** @return positive-int */
    public function getContainerMaxWidth(): int
    {
        return $this->containerMaxWidth;
    }

    // ─── GridAdapterInterface: CSS class generation ─────────────────

    public function getWidthClass(string $viewport, int $width): string
    {
        if ($viewport === static::config()->get('base_viewport_key')) {
            return sprintf(static::config()->get('base_width_format'), $width);
        }

        return sprintf(static::config()->get('responsive_width_format'), $viewport, $width);
    }

    public function getOffsetClass(string $viewport, int $offset): string
    {
        /** @var int $adjustment */
        $adjustment = static::config()->get('offset_adjustment');
        $adjusted = $offset + $adjustment;

        if ($viewport === static::config()->get('base_viewport_key')) {
            return sprintf(static::config()->get('base_offset_format'), $adjusted);
        }

        return sprintf(static::config()->get('responsive_offset_format'), $viewport, $adjusted);
    }

    /** @return list<string> */
    public function getVisibilityClasses(string $viewport): array
    {
        /** @var list<non-empty-string> $keys */
        $keys = array_keys($this->viewports);
        $index = array_search($viewport, $keys, true);
        $isLast = $index === count($keys) - 1;

        /** @var ?string $baseKey */
        $baseKey = static::config()->get('base_viewport_key');

        $hideClass = ($viewport === $baseKey)
            ? static::config()->get('base_hide_class')
            : sprintf(static::config()->get('responsive_hide_format'), $viewport);

        if ($isLast) {
            return [$hideClass];
        }

        $nextKey = $keys[$index + 1];

        return [
            $hideClass,
            sprintf(static::config()->get('responsive_restore_format'), $nextKey),
        ];
    }

    public function getRowClasses(): string
    {
        return sprintf(static::config()->get('row_class_format'), $this->columnCount);
    }

    public function getContainerClass(bool $fluid): string
    {
        return $fluid
            ? static::config()->get('fluid_container_class')
            : static::config()->get('container_class');
    }

    /** @return array<string, string> */
    public function getTitleClassOptions(): array
    {
        return static::config()->get('title_class_options');
    }

    public function getBaseWidthClass(int $width): string
    {
        return sprintf(static::config()->get('base_width_format'), $width);
    }

    public function getBaseOffsetClass(int $offset): string
    {
        /** @var int $adjustment */
        $adjustment = static::config()->get('offset_adjustment');

        return sprintf(static::config()->get('base_offset_format'), $offset + $adjustment);
    }

    public function getOffsetStrategy(): OffsetStrategy
    {
        /** @var string $value */
        $value = static::config()->get('offset_strategy');

        return OffsetStrategy::from($value);
    }

    // ─── ContentLayoutAdapterInterface ──────────────────────────────

    public function getAspectRatioClass(AspectRatio $ratio): ?string
    {
        return static::config()->get('aspect_ratio_classes')[$ratio->value];
    }

    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string
    {
        return static::config()->get('vertical_alignment_classes')[$alignment->value];
    }

    public function getMediaOrderClasses(MediaPosition $position): string
    {
        /** @var string $orderFormat */
        $orderFormat = static::config()->get('order_class_format');
        /** @var string $responsiveFormat */
        $responsiveFormat = static::config()->get('responsive_order_format');

        return match ($position) {
            MediaPosition::First => sprintf($orderFormat, 1),
            MediaPosition::Last => sprintf($orderFormat, 2),
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                sprintf($orderFormat, 1),
                sprintf($responsiveFormat, $this->defaultViewport->key, 2),
            ),
        };
    }

    public function getContentOrderClasses(MediaPosition $position): string
    {
        /** @var string $orderFormat */
        $orderFormat = static::config()->get('order_class_format');
        /** @var string $responsiveFormat */
        $responsiveFormat = static::config()->get('responsive_order_format');

        return match ($position) {
            MediaPosition::First => sprintf($orderFormat, 2),
            MediaPosition::Last => sprintf($orderFormat, 1),
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                sprintf($orderFormat, 2),
                sprintf($responsiveFormat, $this->defaultViewport->key, 1),
            ),
        };
    }

    public function getMediaWidthClass(int $contentColumns): string
    {
        /** @var positive-int $mediaColumns Caller guarantees contentColumns < columnCount */
        $mediaColumns = $this->columnCount - $contentColumns;

        return $this->getWidthClass($this->defaultViewport->key, $mediaColumns);
    }

    public function getContentWidthClass(int $contentColumns): string
    {
        /** @var positive-int $contentColumns Caller guarantees > 0 */
        return $this->getWidthClass($this->defaultViewport->key, $contentColumns);
    }

    /**
     * @param 'left'|'right' $direction
     * @param positive-int $size
     */
    public function getPaddingClass(string $direction, int $size): string
    {
        /** @var array<string, string> $dirMap */
        $dirMap = static::config()->get('padding_direction_map');
        $prefix = $dirMap[$direction];

        return sprintf(static::config()->get('padding_format'), $prefix, $this->defaultViewport->key, $size);
    }

    public function getBaseColumnClass(): ?string
    {
        return static::config()->get('base_column_class');
    }

    // ─── Config validation helpers ──────────────────────────────────

    /**
     * @param array<string, Viewport> $allViewports
     * @return array<string, Viewport>
     * @throws InvalidGridValueException
     */
    private function applyViewportFilter(array $allViewports): array
    {
        /** @var list<string>|null $enabled */
        $enabled = static::config()->get('enabled_viewports');

        if ($enabled === null) {
            return $allViewports;
        }

        if ($enabled === []) {
            throw InvalidGridValueException::forEmptyViewports();
        }

        $filtered = [];

        foreach ($enabled as $key) {
            if (!isset($allViewports[$key])) {
                throw InvalidGridValueException::forViewport($key);
            }

            $filtered[$key] = $allViewports[$key];
        }

        return $filtered;
    }

    /**
     * @return positive-int
     * @throws InvalidGridValueException
     */
    private function resolveColumnCount(): int
    {
        /** @var int $value */
        $value = static::config()->get('total_columns');

        if ($value <= 0) {
            throw InvalidGridValueException::forColumnCount($value);
        }

        /** @var positive-int $value */
        return $value;
    }

    /**
     * @return positive-int
     * @throws InvalidGridValueException
     */
    private function resolveContainerMaxWidth(): int
    {
        /** @var int $value */
        $value = static::config()->get('container_max_width');

        if ($value <= 0) {
            throw InvalidGridValueException::forContainerMaxWidth($value);
        }

        /** @var positive-int $value */
        return $value;
    }

    /**
     * @throws InvalidGridValueException
     */
    private function resolveDefaultViewport(): Viewport
    {
        /** @var string $key */
        $key = static::config()->get('default_viewport');

        if (!isset($this->viewports[$key])) {
            throw InvalidGridValueException::forViewport($key);
        }

        return $this->viewports[$key];
    }
}
