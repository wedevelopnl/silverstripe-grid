<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
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

        // Sentinels: width=0 ensures first viewport always emits (valid widths are >0).
        // Offset=0 suppresses emission of offset-0 at the first viewport (it's the default).
        $prevWidth = 0;
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            if (!isset($effective[$key])) {
                throw InvalidGridValueException::forViewport($key);
            }

            $config = $effective[$key];

            $width = $config->width;
            $offset = $config->offset;
            $visible = $config->visible;

            if (!$visible && $prevVisible) {
                $parts = [...$parts, ...$adapter->getVisibilityClasses($key)];
            } elseif ($visible) {
                if ($width !== $prevWidth || !$prevVisible) {
                    $parts[] = $adapter->getWidthClass($key, $width);
                }

                if ($offset !== $prevOffset) {
                    $parts[] = $adapter->getOffsetClass($key, $offset);
                }
            }

            $prevWidth = $width;
            $prevOffset = $offset;
            $prevVisible = $visible;
        }

        return implode(' ', $parts);
    }
}
