<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\Viewport;

/**
 * Handles expand/compact lifecycle for sparse GridSettings storage.
 *
 * GridSettings uses mobile-first cascade: only viewport overrides that differ
 * from the previous viewport's effective values are stored. This service
 * provides the expand (sparse → full) and compact (full → sparse) operations,
 * plus an atomic applyViewportUpdate that correctly handles cascade resets.
 */
final class GridSettingsCompactor
{
    public function __construct(
        private readonly GridAdapterInterface $adapter,
    ) {}

    /**
     * Expand sparse grid settings into a full viewport array with override flags.
     *
     * Walks viewports smallest→largest with mobile-first cascade to determine
     * each viewport's effective values from sparse data, then compares each
     * non-default viewport against the default viewport's effective values.
     *
     * @param array<string, array{width: int, offset: int, visible: bool}> $sparse
     * @return array<string, array{width: int, offset: int, visible: bool, override: bool}>
     */
    public function expandFromSparse(array $sparse): array
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();

        // Pass 1: mobile-first cascade to determine effective values per viewport
        $effective = [];
        $prevWidth = $columnCount;
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $hasOverride = array_key_exists($key, $sparse);

            $effective[$key] = [
                'width' => $hasOverride ? $sparse[$key]['width'] : $prevWidth,
                'offset' => $hasOverride ? $sparse[$key]['offset'] : $prevOffset,
                'visible' => $hasOverride ? $sparse[$key]['visible'] : $prevVisible,
            ];

            $prevWidth = $effective[$key]['width'];
            $prevOffset = $effective[$key]['offset'];
            $prevVisible = $effective[$key]['visible'];
        }

        // Pass 2: compare each viewport against the default viewport's effective values
        $defaultValues = $effective[$defaultKey];
        $result = [];

        foreach ($viewports as $viewport) {
            $key = $viewport->key;
            $isDefault = $key === $defaultKey;
            $vals = $effective[$key];

            $result[$key] = [
                'width' => $vals['width'],
                'offset' => $vals['offset'],
                'visible' => $vals['visible'],
                'override' => !$isDefault && (
                    $vals['width'] !== $defaultValues['width']
                    || $vals['offset'] !== $defaultValues['offset']
                    || $vals['visible'] !== $defaultValues['visible']
                ),
            ];
        }

        return $result;
    }

    /**
     * Compact full viewport data back to sparse storage.
     *
     * Walks smallest→largest. For each viewport, resolves the effective value
     * (own values if override=true or default viewport, else default viewport's
     * values). Stores only when effective differs from prev. This produces
     * sparse JSON that makes Column::getColumnClasses()'s mobile-first cascade
     * yield the correct results.
     *
     * @param array<string, array{width: int, offset: int, visible: bool, override: bool}> $full
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    public function compactToSparse(array $full): array
    {
        $viewports = $this->adapter->getViewports();
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $columnCount = $this->adapter->getColumnCount();
        $sparse = [];

        // Read the default viewport's values (always present in full data)
        $defaultValues = isset($full[$defaultKey]) ? [
            'width' => $full[$defaultKey]['width'],
            'offset' => $full[$defaultKey]['offset'],
            'visible' => $full[$defaultKey]['visible'],
        ] : [
            'width' => $columnCount,
            'offset' => 0,
            'visible' => true,
        ];

        // Seed with implicit defaults for mobile-first cascade comparison
        $prevWidth = $columnCount;
        $prevOffset = 0;
        $prevVisible = true;

        foreach ($viewports as $viewport) {
            $key = $viewport->key;

            if (!isset($full[$key])) {
                continue;
            }

            $entry = $full[$key];
            $isDefault = $key === $defaultKey;

            // Resolve effective value for this viewport
            if ($isDefault || $entry['override']) {
                $effWidth = $entry['width'];
                $effOffset = $entry['offset'];
                $effVisible = $entry['visible'];
            } else {
                // Non-overridden: uses default viewport values
                $effWidth = $defaultValues['width'];
                $effOffset = $defaultValues['offset'];
                $effVisible = $defaultValues['visible'];
            }

            // Store only if effective differs from previous in the cascade
            if ($effWidth !== $prevWidth || $effOffset !== $prevOffset || $effVisible !== $prevVisible) {
                $sparse[$key] = [
                    'width' => $effWidth,
                    'offset' => $effOffset,
                    'visible' => $effVisible,
                ];
            }

            $prevWidth = $effWidth;
            $prevOffset = $effOffset;
            $prevVisible = $effVisible;
        }

        return $sparse;
    }

    /**
     * Apply a single viewport update to sparse settings with correct cascade handling.
     *
     * Expands current sparse to full representation, updates the target viewport,
     * sets override=true for non-default viewports, then compacts back to sparse.
     * This ensures cascade "reset" entries are generated where needed.
     *
     * @param array<string, array{width: int, offset: int, visible: bool}> $currentSparse
     * @param array{width: int, offset: int, visible: bool} $values
     * @return array<string, array{width: int, offset: int, visible: bool}>
     */
    public function applyViewportUpdate(array $currentSparse, string $viewport, array $values): array
    {
        $defaultKey = $this->adapter->getDefaultViewport()->key;
        $isDefault = $viewport === $defaultKey;

        $full = $this->expandFromSparse($currentSparse);

        $full[$viewport] = [
            'width' => $values['width'],
            'offset' => $values['offset'],
            'visible' => $values['visible'],
            'override' => !$isDefault,
        ];

        return $this->compactToSparse($full);
    }
}
