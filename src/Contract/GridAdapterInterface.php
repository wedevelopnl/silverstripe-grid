<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Contract;

use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Translates grid layout intent into framework-specific CSS classes.
 *
 * Methods receive viewport keys and numeric values, return CSS class strings.
 * No data model dependency — the adapter has no knowledge of elements.
 *
 * Each adapter defines its own viewport set via {@see getViewports()}. Class
 * generation methods accept string viewport keys from that set, keeping the API
 * aligned with the JSON grid settings stored on Column.
 */
interface GridAdapterInterface
{
    /**
     * Viewport definitions supported by this adapter, ordered smallest to largest.
     *
     * @return list<Viewport>
     */
    public function getViewports(): array;

    /**
     * Total number of columns in the grid system (typically 12).
     *
     * @return positive-int
     */
    public function getColumnCount(): int;

    /**
     * Suggested default viewport for the CMS grid editor.
     */
    public function getDefaultViewport(): Viewport;

    /**
     * Width class for the given viewport and column span.
     *
     * @param non-empty-string $viewport
     * @param positive-int $width
     *
     * @example Bootstrap: getWidthClass('md', 6) → 'col-md-6'
     * @example Tailwind:  getWidthClass('md', 6) → 'md:col-span-6'
     * @example Bulma:     getWidthClass('desktop', 6) → 'is-6-desktop'
     */
    public function getWidthClass(string $viewport, int $width): string;

    /**
     * Offset class for the given viewport and column offset.
     *
     * @param non-empty-string $viewport
     * @param int<0, max> $offset
     *
     * @example Bootstrap: getOffsetClass('md', 3) → 'offset-md-3'
     * @example Tailwind:  getOffsetClass('md', 3) → 'md:col-start-4'
     * @example Bulma:     getOffsetClass('desktop', 3) → 'is-offset-3-desktop'
     */
    public function getOffsetClass(string $viewport, int $offset): string;

    /**
     * The CSS class that hides an element at the given viewport.
     *
     * Whether hiding cascades to larger breakpoints is framework-specific and is
     * signalled by {@see self::getRestoreClass()}: frameworks that return a restore
     * class (Bootstrap `d-md-none`, Tailwind `md:hidden`) cascade upward and are
     * undone with the restore class, so callers emit the hide once at the point a
     * column turns hidden; frameworks that return null (Bulma `is-hidden-md-only`)
     * scope each hide to a single viewport, so callers emit a hide at every hidden
     * viewport. The caller (ColumnClassResolver) owns that sequencing decision — it
     * is the only place that knows the per-viewport visibility sequence.
     *
     * @example Bootstrap md: 'd-md-none'
     * @example Bootstrap xs: 'd-none'   (no-infix base viewport)
     *
     * @param non-empty-string $viewport
     */
    public function getHideClass(string $viewport): string;

    /**
     * The CSS class that restores visibility at the given viewport, or null when
     * the framework has no such utility.
     *
     * A non-null restore class means the framework's hide utilities cascade to
     * larger breakpoints and must be explicitly undone where a column turns visible
     * again. null means hides are per-viewport-scoped and no restore is needed.
     *
     * @example Bootstrap md: 'd-md-block'
     * @example Tailwind  md: 'md:block'
     * @example Bulma     tablet: null
     *
     * @param non-empty-string $viewport
     */
    public function getRestoreClass(string $viewport): ?string;

    /**
     * CSS classes for a grid row container.
     *
     * @example Bootstrap: 'row'
     * @example Tailwind:  'grid grid-cols-12'
     * @example Bulma:     'columns is-multiline'
     */
    public function getRowClasses(): string;

    /**
     * CSS class for the outermost grid container.
     *
     * @param bool $fluid Whether the container should span full width
     *
     * @example Bootstrap: getContainerClass(false) → 'container'
     * @example Bootstrap: getContainerClass(true) → 'container-fluid'
     */
    public function getContainerClass(bool $fluid): string;

    /**
     * Dropdown source for element title CSS class selection.
     *
     * Keys are the CSS values, values are human-readable labels —
     * matching the SilverStripe DropdownField source convention.
     *
     * @return array<string, string>
     */
    public function getTitleClassOptions(): array;

    /**
     * Base width class that applies regardless of viewport (for CMS editor preview).
     *
     * The CMS grid editor's viewport is uncontrolled — it renders at whatever
     * size the panel happens to be. Base classes ensure columns always apply
     * without requiring a specific screen width.
     *
     * @param positive-int $width
     *
     * @example Bootstrap: getBaseWidthClass(6) → 'col-6'
     * @example Tailwind:  getBaseWidthClass(6) → 'col-span-6'
     * @example Bulma:     getBaseWidthClass(6) → 'is-6'
     */
    public function getBaseWidthClass(int $width): string;

    /**
     * Base offset class that applies regardless of viewport (for CMS editor preview).
     *
     * @param int<0, max> $offset
     *
     * @example Bootstrap: getBaseOffsetClass(3) → 'offset-3'
     * @example Tailwind:  getBaseOffsetClass(3) → 'col-start-4'
     * @example Bulma:     getBaseOffsetClass(3) → 'is-offset-3'
     */
    public function getBaseOffsetClass(int $offset): string;

    /**
     * How the CSS framework implements column offsets.
     *
     * Margin-based frameworks (Bootstrap, Bulma) use flow-relative `margin-left`.
     * Grid-placement frameworks (Tailwind) use `grid-column-start`.
     * The CMS editor uses this to choose between flex and grid layout for preview.
     */
    public function getOffsetStrategy(): OffsetStrategy;

    /**
     * Maximum container width in pixels at the largest viewport.
     *
     * Used for responsive image sizing calculations. Returns the pixel width
     * of the outermost container at the framework's widest breakpoint.
     *
     * @return positive-int
     */
    public function getContainerMaxWidth(): int;

    /**
     * Pixel width for a given column span at the container's maximum width.
     *
     * Converts a column span to its equivalent pixel width using the grid's
     * column count and container max width. Used for responsive image sizing.
     *
     * @param positive-int $columnSpan
     * @return positive-int
     *
     * @example Bootstrap 12-col, 1320px: getColumnPixelWidth(4) → 440
     * @example Bootstrap 12-col, 1320px: getColumnPixelWidth(6) → 660
     */
    public function getColumnPixelWidth(int $columnSpan): int;
}
