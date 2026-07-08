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

        // Sentinel: width=0 ensures the first viewport always emits (valid widths are >0).
        $prevWidth = 0;
        // Offset classes cascade upward, so the emitted value — not the previous
        // viewport's configured value — determines what is in effect. Tracking the
        // configured value instead would drop the offset entirely after a leading
        // hidden run (configured values compare equal while nothing was emitted) and
        // leave a pre-hidden offset cascading stale past the run. Starts at 0, the
        // browser default, which also suppresses offset-0 at the first viewport.
        $emittedOffset = 0;
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

            if (!$visible) {
                // Emit a hide class when the column is hidden. Frameworks whose hide
                // utilities cascade to larger breakpoints expose a restore class, so
                // one hide at the visible→hidden transition suffices; frameworks with
                // per-viewport-scoped hides (no restore class, e.g. Bulma) need a hide
                // at every hidden viewport, or the run would only hide its first one.
                if ($prevVisible || $adapter->getRestoreClass($key) === null) {
                    $parts[] = $adapter->getHideClass($key);
                }
            } else {
                if (!$prevVisible) {
                    // The column becomes visible again after a hidden run. On cascade
                    // frameworks the upward-cascading hide must be undone here with a
                    // restore class (null on per-viewport frameworks, which need none).
                    $restore = $adapter->getRestoreClass($key);
                    if ($restore !== null) {
                        $parts[] = $restore;
                    }
                }

                if ($width !== $prevWidth || !$prevVisible) {
                    $parts[] = $adapter->getWidthClass($key, $width);
                }

                if ($offset !== $emittedOffset) {
                    $parts[] = $adapter->getOffsetClass($key, $offset);
                    $emittedOffset = $offset;
                }
            }

            $prevWidth = $width;
            $prevVisible = $visible;
        }

        return implode(' ', $parts);
    }
}
