<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Grid adapter for Bootstrap 5's Flexbox grid system.
 *
 * Implements Bootstrap's responsive column classes using its six default
 * breakpoints: xs (mobile-first default), sm (576px), md (768px), lg (992px),
 * xl (1200px), and xxl (1400px).
 *
 * Bootstrap treats xs as the mobile-first default — width classes use
 * `col-{n}` instead of `col-xs-{n}`, offset classes use `offset-{n}`
 * instead of `offset-xs-{n}`, and visibility uses `d-none` instead of
 * `d-xs-none`. All other viewports include the viewport infix.
 */
final class BootstrapAdapter implements GridAdapterInterface
{
    use GridAdapterConfiguration;

    private const int DEFAULT_COLUMNS = 12;
    private const int DEFAULT_CONTAINER_MAX_WIDTH = 1320;
    private const string DEFAULT_VIEWPORT_KEY = 'md';

    /** @var array<string, Viewport> */
    private array $viewports;

    /**
     * Visibility class pairs keyed by viewport.
     *
     * Bootstrap's responsive display utilities:
     * - xs viewport: `d-none` + `d-{next}-block` — no viewport infix for the
     *   mobile-first default, restore at the next enabled breakpoint.
     * - Other non-last viewports: `d-{vp}-none` + `d-{next}-block` — the infix
     *   scopes hiding from that breakpoint upward, restore at next enabled.
     * - Last enabled viewport: `d-{vp}-none` — no restore needed.
     *
     * @var array<string, list<string>>
     */
    private array $visibilityMap;

    /** @var positive-int */
    private int $columnCount;

    /** @var positive-int */
    private int $containerMaxWidth;

    private Viewport $defaultViewport;

    public function __construct()
    {
        $allViewports = [
            'xs' => new Viewport('xs', 'Extra Small'),
            'sm' => new Viewport('sm', 'Small'),
            'md' => new Viewport('md', 'Medium'),
            'lg' => new Viewport('lg', 'Large'),
            'xl' => new Viewport('xl', 'Extra Large'),
            'xxl' => new Viewport('xxl', 'Extra Extra Large'),
        ];

        $this->viewports = $this->applyViewportFilter($allViewports);
        $this->visibilityMap = $this->buildVisibilityMap();
        $this->columnCount = $this->resolveColumnCount(self::DEFAULT_COLUMNS);
        $this->containerMaxWidth = $this->resolveContainerMaxWidth(self::DEFAULT_CONTAINER_MAX_WIDTH);
        $this->defaultViewport = $this->resolveDefaultViewport(self::DEFAULT_VIEWPORT_KEY, $this->viewports);
    }

    #[\Override]
    public function getViewports(): array
    {
        return array_values($this->viewports);
    }

    #[\Override]
    public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    #[\Override]
    public function getDefaultViewport(): Viewport
    {
        return $this->defaultViewport;
    }

    #[\Override]
    public function getWidthClass(string $viewport, int $width): string
    {
        if ($viewport === 'xs') {
            return sprintf('col-%d', $width);
        }

        return sprintf('col-%s-%d', $viewport, $width);
    }

    #[\Override]
    public function getOffsetClass(string $viewport, int $offset): string
    {
        if ($viewport === 'xs') {
            return sprintf('offset-%d', $offset);
        }

        return sprintf('offset-%s-%d', $viewport, $offset);
    }

    #[\Override]
    public function getVisibilityClasses(string $viewport): array
    {
        return $this->visibilityMap[$viewport];
    }

    #[\Override]
    public function getRowClasses(): string
    {
        return 'row';
    }

    #[\Override]
    public function getContainerClass(bool $fluid): string
    {
        if ($fluid) {
            return 'container-fluid';
        }

        return 'container';
    }

    #[\Override]
    public function getTitleClassOptions(): array
    {
        return [
            'display-1' => 'Display 1',
            'display-2' => 'Display 2',
            'display-3' => 'Display 3',
            'display-4' => 'Display 4',
            'display-5' => 'Display 5',
            'display-6' => 'Display 6',
            'h1' => 'Heading 1',
            'h2' => 'Heading 2',
            'h3' => 'Heading 3',
            'h4' => 'Heading 4',
            'h5' => 'Heading 5',
            'h6' => 'Heading 6',
        ];
    }

    #[\Override]
    public function getBaseWidthClass(int $width): string
    {
        return $this->getWidthClass('xs', $width);
    }

    #[\Override]
    public function getBaseOffsetClass(int $offset): string
    {
        return $this->getOffsetClass('xs', $offset);
    }

    #[\Override]
    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::Margin;
    }

    #[\Override]
    public function getContainerMaxWidth(): int
    {
        return $this->containerMaxWidth;
    }

    #[\Override]
    public function getCssPath(): string
    {
        return 'client/dist/bootstrap-grid.css';
    }

    #[\Override]
    public function getContentLayoutClassMap(): ContentLayoutClassMap
    {
        return ContentLayoutClassMap::bootstrap();
    }

    /**
     * Builds the visibility class map from the active (possibly filtered) viewport set.
     *
     * Bootstrap's xs viewport is special: it uses the no-infix `d-none` form.
     * All other viewports use `d-{vp}-none`. Restore always targets the next
     * *enabled* viewport. The last enabled viewport has no restore class.
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

            // Bootstrap: xs uses no-infix `d-none`, all others use `d-{vp}-none`
            $hideClass = $key === 'xs'
                ? 'd-none'
                : sprintf('d-%s-none', $key);

            if ($isLast) {
                $map[$key] = [$hideClass];
            } else {
                $nextKey = $keys[$index + 1];
                $map[$key] = [
                    $hideClass,
                    sprintf('d-%s-block', $nextKey),
                ];
            }
        }

        return $map;
    }
}
