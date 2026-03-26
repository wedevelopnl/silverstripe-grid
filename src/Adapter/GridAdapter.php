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
 * contains only shared logic: sprintf formatting, enum lookups, visibility
 * map computation, and config validation.
 *
 * Concrete framework adapters (BootstrapAdapter, TailwindAdapter, BulmaAdapter)
 * extend this class with zero methods — they only override the static properties
 * to declare their CSS vocabulary. Projects can override any property via YAML.
 *
 * YAML-configurable properties (set on the concrete preset class):
 *
 * Grid topology:
 * - `viewport_definitions` (array<string, string>): key → label, ordered small→large
 * - `total_columns` (int): Grid column count
 * - `container_max_width` (int): Max container width in px
 * - `default_viewport` (string): Default viewport key for CMS editor
 * - `enabled_viewports` (list<string>|null): Restrict viewports, null = all
 *
 * Width/offset formats:
 * - `base_viewport_key` (?string): Viewport using base format, null if none
 * - `base_width_format` (string): sprintf for base width class (%d = width)
 * - `responsive_width_format` (string): sprintf for responsive width (%1$s = viewport, %2$d = width)
 * - `base_offset_format` (string): sprintf for base offset class (%d = offset)
 * - `responsive_offset_format` (string): sprintf for responsive offset (%1$s = viewport, %2$d = offset)
 * - `offset_adjustment` (int): Added to offset before formatting (0 for most, 1 for Tailwind col-start)
 *
 * Visibility formats:
 * - `base_hide_class` (string): Hide class for the base viewport
 * - `responsive_hide_format` (string): sprintf for responsive hide (%s = viewport)
 * - `responsive_restore_format` (string): sprintf for responsive restore (%s = viewport)
 *
 * Container/structure:
 * - `row_class_format` (string): sprintf for row classes (%d = column count, unused arg OK)
 * - `container_class` (string): Container wrapper class
 * - `fluid_container_class` (string): Fluid container wrapper class
 * - `title_class_options` (array<string, string>): CSS class → human label
 * - `offset_strategy` (string): 'margin' or 'grid-placement'
 *
 * Content layout:
 * - `aspect_ratio_classes` (array<string, ?string>): enum value → CSS class
 * - `vertical_alignment_classes` (array<string, string>): enum value → CSS class
 * - `order_class_format` (string): sprintf for fixed order (%d = position)
 * - `responsive_order_format` (string): sprintf for responsive order (%1$s = viewport, %2$d = position)
 * - `padding_direction_map` (array<string, string>): 'left'|'right' → CSS prefix
 * - `padding_format` (string): sprintf for padding (%1$s = dir prefix, %2$s = viewport, %3$d = size)
 * - `base_column_class` (?string): Framework base class (e.g. Bulma's 'column'), null if not needed
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

    private static string $base_width_format = '';

    /** sprintf: %1$s = viewport, %2$d = width */
    private static string $responsive_width_format = '';

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

    /** sprintf: %d = column count (extra args ignored for static formats) */
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

    // ─── Resolved instance state ────────────────────────────────────

    /** @var array<string, Viewport> */
    private readonly array $viewports;

    /** @var positive-int */
    private readonly int $columnCount;

    /** @var positive-int */
    private readonly int $containerMaxWidth;

    private readonly Viewport $defaultViewport;

    private readonly ?string $baseViewportKey;

    private readonly string $baseWidthFormat;

    private readonly string $responsiveWidthFormat;

    private readonly string $baseOffsetFormat;

    private readonly string $responsiveOffsetFormat;

    private readonly int $offsetAdjustment;

    private readonly string $baseHideClass;

    private readonly string $responsiveHideFormat;

    private readonly string $responsiveRestoreFormat;

    private readonly string $rowClassFormat;

    private readonly string $containerClassValue;

    private readonly string $fluidContainerClassValue;

    /** @var array<string, string> */
    private readonly array $titleClassOptionsValue;

    private readonly OffsetStrategy $offsetStrategyValue;

    /** @var array<string, ?string> */
    private readonly array $aspectRatioClassesValue;

    /** @var array<string, string> */
    private readonly array $verticalAlignmentClassesValue;

    private readonly string $orderClassFormat;

    private readonly string $responsiveOrderFormat;

    /** @var array<string, string> */
    private readonly array $paddingDirectionMapValue;

    private readonly string $paddingFormatValue;

    private readonly ?string $baseColumnClassValue;

    /**
     * Pre-computed visibility class pairs keyed by viewport.
     *
     * @var array<string, list<string>>
     */
    private readonly array $visibilityMap;

    public function __construct()
    {
        // Read all config — null-coalesce with PHP static defaults so that
        // MemoryConfigCollection (which doesn't load PHP statics) works in tests
        $this->baseViewportKey = static::config()->get('base_viewport_key');
        $this->baseWidthFormat = static::config()->get('base_width_format');
        $this->responsiveWidthFormat = static::config()->get('responsive_width_format');
        $this->baseOffsetFormat = static::config()->get('base_offset_format');
        $this->responsiveOffsetFormat = static::config()->get('responsive_offset_format');
        $this->offsetAdjustment = static::config()->get('offset_adjustment');
        $this->baseHideClass = static::config()->get('base_hide_class');
        $this->responsiveHideFormat = static::config()->get('responsive_hide_format');
        $this->responsiveRestoreFormat = static::config()->get('responsive_restore_format');
        $this->rowClassFormat = static::config()->get('row_class_format');
        $this->containerClassValue = static::config()->get('container_class');
        $this->fluidContainerClassValue = static::config()->get('fluid_container_class');
        $this->titleClassOptionsValue = static::config()->get('title_class_options');
        $this->aspectRatioClassesValue = static::config()->get('aspect_ratio_classes');
        $this->verticalAlignmentClassesValue = static::config()->get('vertical_alignment_classes');
        $this->orderClassFormat = static::config()->get('order_class_format');
        $this->responsiveOrderFormat = static::config()->get('responsive_order_format');
        $this->paddingDirectionMapValue = static::config()->get('padding_direction_map');
        $this->paddingFormatValue = static::config()->get('padding_format');
        $this->baseColumnClassValue = static::config()->get('base_column_class');

        // Resolve offset strategy
        /** @var string $strategyValue */
        $strategyValue = static::config()->get('offset_strategy');
        $strategy = OffsetStrategy::tryFrom($strategyValue);

        if ($strategy === null) {
            throw new InvalidGridValueException(
                userMessage: 'The configured offset strategy is invalid.',
                detailedMessage: sprintf('Offset strategy must be "margin" or "grid-placement", got "%s".', $strategyValue),
                statusCode: 422,
            );
        }

        $this->offsetStrategyValue = $strategy;

        // Build viewport objects from config
        /** @var array<non-empty-string, non-empty-string> $viewportDefs */
        $viewportDefs = static::config()->get('viewport_definitions');
        $allViewports = [];

        foreach ($viewportDefs as $key => $label) {
            $allViewports[$key] = new Viewport($key, $label);
        }

        // Apply viewport filter, validate, resolve topology
        $this->viewports = $this->applyViewportFilter($allViewports);
        $this->columnCount = $this->resolvePositiveInt('total_columns');
        $this->containerMaxWidth = $this->resolveContainerMaxWidth();
        $this->defaultViewport = $this->resolveDefaultViewport();

        // Pre-compute visibility map from resolved viewports + format strings
        $this->visibilityMap = $this->buildVisibilityMap();
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
        if ($viewport === $this->baseViewportKey) {
            return sprintf($this->baseWidthFormat, $width);
        }

        return sprintf($this->responsiveWidthFormat, $viewport, $width);
    }

    public function getOffsetClass(string $viewport, int $offset): string
    {
        $adjusted = $offset + $this->offsetAdjustment;

        if ($viewport === $this->baseViewportKey) {
            return sprintf($this->baseOffsetFormat, $adjusted);
        }

        return sprintf($this->responsiveOffsetFormat, $viewport, $adjusted);
    }

    /** @return list<string> */
    public function getVisibilityClasses(string $viewport): array
    {
        return $this->visibilityMap[$viewport];
    }

    public function getRowClasses(): string
    {
        return sprintf($this->rowClassFormat, $this->columnCount);
    }

    public function getContainerClass(bool $fluid): string
    {
        return $fluid ? $this->fluidContainerClassValue : $this->containerClassValue;
    }

    /** @return array<string, string> */
    public function getTitleClassOptions(): array
    {
        return $this->titleClassOptionsValue;
    }

    public function getBaseWidthClass(int $width): string
    {
        return sprintf($this->baseWidthFormat, $width);
    }

    public function getBaseOffsetClass(int $offset): string
    {
        return sprintf($this->baseOffsetFormat, $offset + $this->offsetAdjustment);
    }

    public function getOffsetStrategy(): OffsetStrategy
    {
        return $this->offsetStrategyValue;
    }

    // ─── ContentLayoutAdapterInterface ──────────────────────────────

    public function getAspectRatioClass(AspectRatio $ratio): ?string
    {
        return $this->aspectRatioClassesValue[$ratio->value];
    }

    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string
    {
        return $this->verticalAlignmentClassesValue[$alignment->value];
    }

    public function getMediaOrderClasses(MediaPosition $position): string
    {
        return match ($position) {
            MediaPosition::First => sprintf($this->orderClassFormat, 1),
            MediaPosition::Last => sprintf($this->orderClassFormat, 2),
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                sprintf($this->orderClassFormat, 1),
                sprintf($this->responsiveOrderFormat, $this->defaultViewport->key, 2),
            ),
        };
    }

    public function getContentOrderClasses(MediaPosition $position): string
    {
        return match ($position) {
            MediaPosition::First => sprintf($this->orderClassFormat, 2),
            MediaPosition::Last => sprintf($this->orderClassFormat, 1),
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                sprintf($this->orderClassFormat, 2),
                sprintf($this->responsiveOrderFormat, $this->defaultViewport->key, 1),
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
        $prefix = $this->paddingDirectionMapValue[$direction];

        return sprintf($this->paddingFormatValue, $prefix, $this->defaultViewport->key, $size);
    }

    public function getBaseColumnClass(): ?string
    {
        return $this->baseColumnClassValue;
    }

    // ─── Config resolution helpers ──────────────────────────────────

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
     * Resolve a positive-int config value (total_columns).
     *
     * @return positive-int
     * @throws InvalidGridValueException
     */
    private function resolvePositiveInt(string $configKey): int
    {
        /** @var int $value */
        $value = static::config()->get($configKey);

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

    /**
     * Builds the visibility class map from the active viewport set.
     *
     * For each viewport:
     * - Last viewport: single hide class
     * - All others: hide class + restore class at the next enabled viewport
     *
     * @return array<string, list<string>>
     */
    private function buildVisibilityMap(): array
    {
        $map = [];
        /** @var list<non-empty-string> $keys */
        $keys = array_keys($this->viewports);
        $count = count($keys);

        foreach ($keys as $index => $key) {
            $isLast = $index === $count - 1;

            if ($isLast) {
                $map[$key] = [$this->formatHideClass($key)];
            } else {
                $nextKey = $keys[$index + 1];
                $map[$key] = [
                    $this->formatHideClass($key),
                    $this->formatRestoreClass($nextKey),
                ];
            }
        }

        return $map;
    }

    /**
     * @param non-empty-string $viewportKey
     */
    private function formatHideClass(string $viewportKey): string
    {
        if ($viewportKey === $this->baseViewportKey) {
            return $this->baseHideClass;
        }

        return sprintf($this->responsiveHideFormat, $viewportKey);
    }

    /**
     * @param non-empty-string $viewportKey
     */
    private function formatRestoreClass(string $viewportKey): string
    {
        return sprintf($this->responsiveRestoreFormat, $viewportKey);
    }
}
