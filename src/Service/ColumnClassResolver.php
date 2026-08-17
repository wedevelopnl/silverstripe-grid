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
 *
 * The framework's base column class, when it has one, leads the list — Bulma's
 * width helpers are all scoped `.column.is-{n}` and match nothing without it.
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

        $baseColumnClass = $adapter->getBaseColumnClass();
        if ($baseColumnClass !== null) {
            $parts[] = $baseColumnClass;
        }

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
