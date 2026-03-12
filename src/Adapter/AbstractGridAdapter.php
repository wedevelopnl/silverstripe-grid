<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use Override;
use SilverStripe\Core\Config\Configurable;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Exception\InvalidGridValueException;
use WeDevelop\Grid\Value\Viewport;

/**
 * Base class for grid adapters providing shared configuration, viewport management,
 * and visibility map generation.
 *
 * Concrete adapters extend this class and provide:
 * - Viewport definitions and defaults via constructor arguments
 * - Framework-specific CSS class generation via abstract methods
 * - Visibility class formatting via {@see formatHideClass()} and {@see formatRestoreClass()}
 *
 * YAML config properties (set on the concrete adapter class):
 * - `enabled_viewports` (list<string>|null): Restrict viewports. Null keeps all.
 * - `total_columns` (int|null): Override column count. Null defers to adapter.
 * - `default_viewport` (string|null): Override default viewport key. Null defers to adapter.
 * - `container_max_width` (int|null): Override container max width. Null defers to adapter.
 */
abstract class AbstractGridAdapter implements GridAdapterInterface
{
    use Configurable;

    /** @var list<string>|null */
    private static ?array $enabled_viewports = null;

    private static ?int $total_columns = null;

    private static ?string $default_viewport = null;

    private static ?int $container_max_width = null;

    /** @var array<string, Viewport> */
    protected readonly array $viewports;

    /**
     * Pre-computed visibility class pairs keyed by viewport.
     *
     * @var array<string, list<string>>
     */
    private readonly array $visibilityMap;

    /** @var positive-int */
    private readonly int $columnCount;

    /** @var positive-int */
    private readonly int $containerMaxWidth;

    private readonly Viewport $defaultViewport;

    /**
     * @param array<string, Viewport> $allViewports Full viewport definitions keyed by viewport key
     * @param positive-int $defaultColumns Default column count for this adapter
     * @param positive-int $defaultContainerMaxWidth Default container max width in px
     * @param string $defaultViewportKey Default viewport key for this adapter
     */
    public function __construct(
        array $allViewports,
        int $defaultColumns,
        int $defaultContainerMaxWidth,
        string $defaultViewportKey,
    ) {
        $this->viewports = $this->applyViewportFilter($allViewports);
        $this->visibilityMap = $this->buildVisibilityMap();
        $this->columnCount = $this->resolveColumnCount($defaultColumns);
        $this->containerMaxWidth = $this->resolveContainerMaxWidth($defaultContainerMaxWidth);
        $this->defaultViewport = $this->resolveDefaultViewport($defaultViewportKey, $this->viewports);
    }

    // ─── Final getters (identical across all adapters) ──────────────

    /** @return list<Viewport> */
    #[Override]
    final public function getViewports(): array
    {
        return array_values($this->viewports);
    }

    /** @return positive-int */
    #[Override]
    final public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    #[Override]
    final public function getDefaultViewport(): Viewport
    {
        return $this->defaultViewport;
    }

    /** @return positive-int */
    #[Override]
    final public function getContainerMaxWidth(): int
    {
        return $this->containerMaxWidth;
    }

    /** @return list<string> */
    #[Override]
    final public function getVisibilityClasses(string $viewport): array
    {
        return $this->visibilityMap[$viewport];
    }

    // ─── Visibility format hooks ────────────────────────────────────

    /**
     * Format the CSS class that hides an element from the given viewport upward.
     *
     * @example Bootstrap xs: 'd-none', Bootstrap md: 'd-md-none'
     * @example Tailwind: 'md:hidden'
     * @example Bulma: 'is-hidden-desktop'
     */
    abstract protected function formatHideClass(string $viewportKey): string;

    /**
     * Format the CSS class that restores visibility at the given viewport.
     *
     * @example Bootstrap: 'd-md-block'
     * @example Tailwind: 'md:block'
     * @example Bulma: 'is-block-desktop'
     */
    abstract protected function formatRestoreClass(string $viewportKey): string;

    // ─── Config resolution helpers ──────────────────────────────────

    /**
     * Filter the full viewport map to only enabled viewports.
     *
     * @param array<string, Viewport> $allViewports Full viewport definitions keyed by viewport key
     * @return array<string, Viewport> Filtered viewport map (preserves definition order)
     * @throws InvalidGridValueException If enabled_viewports is empty or contains unknown keys
     */
    protected function applyViewportFilter(array $allViewports): array
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
     * Resolve the effective column count, applying any YAML override.
     *
     * @param positive-int $adapterDefault The adapter's built-in column count
     * @return positive-int
     * @throws InvalidGridValueException If the override is zero or negative
     */
    protected function resolveColumnCount(int $adapterDefault): int
    {
        /** @var int|null $override */
        $override = static::config()->get('total_columns');

        if ($override === null) {
            return $adapterDefault;
        }

        if ($override <= 0) {
            throw InvalidGridValueException::forColumnCount($override);
        }

        /** @var positive-int $override */
        return $override;
    }

    /**
     * Resolve the effective default viewport, applying any YAML override.
     *
     * @param string $adapterDefaultKey The adapter's built-in default viewport key
     * @param array<string, Viewport> $viewports Active (possibly filtered) viewport map
     * @throws InvalidGridValueException If the resolved key is not in the active viewport map
     */
    protected function resolveDefaultViewport(string $adapterDefaultKey, array $viewports): Viewport
    {
        /** @var string|null $override */
        $override = static::config()->get('default_viewport');
        $key = $override ?? $adapterDefaultKey;

        if (!isset($viewports[$key])) {
            throw InvalidGridValueException::forViewport($key);
        }

        return $viewports[$key];
    }

    /**
     * Resolve the effective container max width, applying any YAML override.
     *
     * @param positive-int $adapterDefault The adapter's built-in container max width
     * @return positive-int
     * @throws InvalidGridValueException If the override is zero or negative
     */
    protected function resolveContainerMaxWidth(int $adapterDefault): int
    {
        /** @var int|null $override */
        $override = static::config()->get('container_max_width');

        if ($override === null) {
            return $adapterDefault;
        }

        if ($override <= 0) {
            throw InvalidGridValueException::forContainerMaxWidth($override);
        }

        /** @var positive-int $override */
        return $override;
    }

    /**
     * Builds the visibility class map from the active (possibly filtered) viewport set.
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
}
