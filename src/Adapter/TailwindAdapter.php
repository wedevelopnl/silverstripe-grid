<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Tailwind CSS grid adapter using utility classes for a 12-column CSS Grid layout.
 *
 * Viewport breakpoints match Tailwind v3/v4 defaults (sm through 2xl).
 * All class generation is stateless — no DOM, no config file needed.
 */
final class TailwindAdapter implements GridAdapterInterface
{
    use GridAdapterConfiguration;

    private const int DEFAULT_COLUMNS = 12;
    private const int DEFAULT_CONTAINER_MAX_WIDTH = 1536;
    private const string DEFAULT_VIEWPORT_KEY = 'sm';

    /** @var array<string, Viewport> */
    private array $viewports;

    /** @var positive-int */
    private int $columnCount;

    /** @var positive-int */
    private int $containerMaxWidth;

    private Viewport $defaultViewport;

    public function __construct()
    {
        $allViewports = [
            'sm' => new Viewport('sm', 'Small'),
            'md' => new Viewport('md', 'Medium'),
            'lg' => new Viewport('lg', 'Large'),
            'xl' => new Viewport('xl', 'Extra Large'),
            '2xl' => new Viewport('2xl', '2X Large'),
        ];

        $this->viewports = $this->applyViewportFilter($allViewports);
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
        return sprintf('%s:col-span-%d', $viewport, $width);
    }

    /**
     * Tailwind's col-start is 1-based, so an offset of N columns means col-start-(N+1).
     */
    #[\Override]
    public function getOffsetClass(string $viewport, int $offset): string
    {
        return sprintf('%s:col-start-%d', $viewport, $offset + 1);
    }

    /**
     * Tailwind uses `{vp}:hidden` to hide and `{next}:block` to restore.
     * "Next" means the next enabled viewport. Last has no restore.
     */
    #[\Override]
    public function getVisibilityClasses(string $viewport): array
    {
        $keys = array_keys($this->viewports);
        $index = array_search($viewport, $keys, true);
        $isLast = $index === count($keys) - 1;

        if ($isLast) {
            return [sprintf('%s:hidden', $viewport)];
        }

        $nextKey = $keys[$index + 1];

        return [
            sprintf('%s:hidden', $viewport),
            sprintf('%s:block', $nextKey),
        ];
    }

    #[\Override]
    public function getRowClasses(): string
    {
        return sprintf('grid grid-cols-%d', $this->columnCount);
    }

    #[\Override]
    public function getContainerClass(bool $fluid): string
    {
        return $fluid ? 'w-full' : 'container mx-auto';
    }

    #[\Override]
    public function getTitleClassOptions(): array
    {
        return [
            'text-4xl' => 'Heading 1',
            'text-3xl' => 'Heading 2',
            'text-2xl' => 'Heading 3',
            'text-xl' => 'Heading 4',
            'text-lg' => 'Heading 5',
            'text-base' => 'Heading 6',
        ];
    }

    #[\Override]
    public function getBaseWidthClass(int $width): string
    {
        return sprintf('col-span-%d', $width);
    }

    /**
     * Tailwind's col-start is 1-based, so an offset of N columns means col-start-(N+1).
     */
    #[\Override]
    public function getBaseOffsetClass(int $offset): string
    {
        return sprintf('col-start-%d', $offset + 1);
    }

    #[\Override]
    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::GridPlacement;
    }

    #[\Override]
    public function getContainerMaxWidth(): int
    {
        return $this->containerMaxWidth;
    }

    #[\Override]
    public function getCssPath(): ?string
    {
        return null;
    }
}
