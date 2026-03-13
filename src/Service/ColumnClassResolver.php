<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;

/**
 * Resolves CSS classes for a grid column using mobile-first cascade.
 *
 * Walks adapter viewports smallest→largest, resolving effective settings
 * via cascade. Only emits CSS classes at breakpoints where the effective
 * value changes from the previous breakpoint. The base viewport always
 * emits a width class (defaults to full-width when no settings exist).
 */
final class ColumnClassResolver
{
    /**
     * @param array<string, array{width: positive-int, offset: int<0, max>, visible: bool}> $settings
     */
    public static function resolve(array $settings, GridAdapterInterface $adapter): string
    {
        $parts = [];
        $columnCount = $adapter->getColumnCount();
        $viewports = $adapter->getViewports();

        // Track effective state for mobile-first cascade (starts at implicit defaults)
        $prevWidth = $columnCount;
        $prevOffset = 0;
        $prevVisible = true;
        $isFirst = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $config = $settings[$key] ?? null;

            // Resolve effective values: explicit override or inherited from previous
            $width = $config['width'] ?? $prevWidth;
            $offset = $config['offset'] ?? $prevOffset;
            $visible = $config['visible'] ?? $prevVisible;

            if (!$visible && $prevVisible) {
                // Transitioning to hidden — emit visibility classes
                $parts = [...$parts, ...$adapter->getVisibilityClasses($key)];
            } elseif ($visible) {
                // Emit width when it changes or at the base viewport
                if ($isFirst || $width !== $prevWidth || (!$prevVisible)) {
                    $parts[] = $adapter->getWidthClass($key, $width);
                }

                // Emit offset when it changes (including reset to 0)
                if ($isFirst && $offset > 0) {
                    $parts[] = $adapter->getOffsetClass($key, $offset);
                } elseif (!$isFirst && $offset !== $prevOffset) {
                    $parts[] = $adapter->getOffsetClass($key, $offset);
                }
            }

            $prevWidth = $width;
            $prevOffset = $offset;
            $prevVisible = $visible;
            $isFirst = false;
        }

        return implode(' ', $parts);
    }
}
