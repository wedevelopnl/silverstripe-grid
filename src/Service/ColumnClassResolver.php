<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\ViewportConfig;

/**
 * Resolves CSS classes for a grid column from pre-resolved viewport settings.
 *
 * Walks adapter viewports smallest→largest. Only emits CSS classes at breakpoints
 * where the effective value changes from the previous breakpoint. The first
 * viewport always emits a width class.
 */
final class ColumnClassResolver
{
    /**
     * @param array<non-empty-string, ViewportConfig> $effective Pre-resolved effective settings per viewport
     */
    public static function resolve(array $effective, GridAdapterInterface $adapter): string
    {
        $parts = [];
        $viewports = $adapter->getViewports();

        // Track effective state for mobile-first CSS emission
        $prevWidth = 0;
        $prevOffset = 0;
        $prevVisible = true;
        $isFirst = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $config = $effective[$key] ?? null;

            if ($config === null) {
                continue;
            }

            /** @var positive-int $width Validated by GridSettingsFieldValidator at write time */
            $width = $config->width;
            /** @var non-negative-int $offset Validated by GridSettingsFieldValidator at write time */
            $offset = $config->offset;
            $visible = $config->visible;

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
