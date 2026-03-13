<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Contract;

use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Translates media-layout intent into framework-specific CSS classes.
 *
 * Complements {@see GridAdapterInterface} — grid handles column widths and
 * offsets, this adapter handles content layout concerns: aspect ratios,
 * ordering, alignment, and directional padding.
 */
interface ContentLayoutAdapterInterface
{
    /**
     * Aspect ratio wrapper class. Null for Auto (no constraint).
     */
    public function getAspectRatioClass(AspectRatio $ratio): ?string;

    /**
     * Vertical alignment class for the row wrapper (flex/grid alignment).
     */
    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string;

    /**
     * Order classes for the media column based on position intent.
     */
    public function getMediaOrderClasses(MediaPosition $position): string;

    /**
     * Order classes for the content column based on position intent.
     */
    public function getContentOrderClasses(MediaPosition $position): string;

    /**
     * Width class for the media column (total columns - contentColumns).
     */
    public function getMediaWidthClass(int $contentColumns): string;

    /**
     * Width class for the content column.
     */
    public function getContentWidthClass(int $contentColumns): string;

    /**
     * Directional padding/margin class for gap between columns.
     *
     * @param 'left'|'right' $direction
     * @param positive-int $size
     */
    public function getPaddingClass(string $direction, int $size): string;

    /**
     * Base column class required by some frameworks (e.g. Bulma's 'column').
     * Null if not needed.
     */
    public function getBaseColumnClass(): ?string;
}
