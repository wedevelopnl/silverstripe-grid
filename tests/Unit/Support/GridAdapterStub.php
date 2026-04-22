<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Support;

use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Deterministic GridAdapterInterface implementation for unit tests.
 *
 * Default: 3 viewports (xs, md, lg), 12 columns, default viewport = xs.
 * CSS patterns: col-{vp}-{w}, offset-{vp}-{o}, hidden-{vp} + visible-{vp}.
 */
final class GridAdapterStub implements GridAdapterInterface
{
    /** @var list<Viewport> */
    private readonly array $viewports;
    private readonly Viewport $defaultViewport;

    /**
     * @param list<Viewport>|null $viewports
     */
    public function __construct(
        ?array $viewports = null,
        private readonly int $columnCount = 12,
        ?Viewport $defaultViewport = null,
    ) {
        $this->viewports = $viewports ?? [
            new Viewport('xs', 'Extra Small', 0),
            new Viewport('md', 'Medium', 768),
            new Viewport('lg', 'Large', 992),
        ];
        $this->defaultViewport = $defaultViewport ?? $this->viewports[0];
    }

    public function getViewports(): array
    {
        return $this->viewports;
    }

    public function getColumnCount(): int
    {
        return $this->columnCount;
    }

    public function getDefaultViewport(): Viewport
    {
        return $this->defaultViewport;
    }

    public function getWidthClass(string $viewport, int $width): string
    {
        return sprintf('col-%s-%d', $viewport, $width);
    }

    public function getOffsetClass(string $viewport, int $offset): string
    {
        return sprintf('offset-%s-%d', $viewport, $offset);
    }

    public function getVisibilityClasses(string $viewport): array
    {
        $index = $this->viewportIndex($viewport);
        $viewports = $this->viewports;

        if ($index === count($viewports) - 1) {
            return [sprintf('hidden-%s', $viewport)];
        }

        return [
            sprintf('hidden-%s', $viewport),
            sprintf('visible-%s', $viewports[$index + 1]->key),
        ];
    }

    public function getRowClasses(): string
    {
        return 'row';
    }

    public function getContainerClass(bool $fluid): string
    {
        return $fluid ? 'container-fluid' : 'container';
    }

    public function getTitleClassOptions(): array
    {
        return ['h1' => 'Heading 1', 'h2' => 'Heading 2'];
    }

    public function getBaseWidthClass(int $width): string
    {
        return sprintf('col-%d', $width);
    }

    public function getBaseOffsetClass(int $offset): string
    {
        return sprintf('offset-%d', $offset);
    }

    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::Margin;
    }

    public function getContainerMaxWidth(): int
    {
        return 1320;
    }

    public function getColumnPixelWidth(int $columnSpan): int
    {
        return (int) round($this->getContainerMaxWidth() * $columnSpan / $this->columnCount);
    }

    private function viewportIndex(string $key): int
    {
        foreach ($this->viewports as $i => $vp) {
            if ($vp->key === $key) {
                return $i;
            }
        }

        return 0;
    }
}
