<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use Override;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Tailwind CSS grid adapter using utility classes for a 12-column CSS Grid layout.
 *
 * Viewport breakpoints match Tailwind v3/v4 defaults (sm through 2xl).
 * All class generation is stateless — no DOM, no config file needed.
 */
final class TailwindAdapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                'sm' => new Viewport('sm', 'Small'),
                'md' => new Viewport('md', 'Medium'),
                'lg' => new Viewport('lg', 'Large'),
                'xl' => new Viewport('xl', 'Extra Large'),
                '2xl' => new Viewport('2xl', '2X Large'),
            ],
            defaultColumns: 12,
            defaultContainerMaxWidth: 1536,
            defaultViewportKey: 'sm',
        );
    }

    #[Override]
    public function getWidthClass(string $viewport, int $width): string
    {
        return sprintf('%s:col-span-%d', $viewport, $width);
    }

    /**
     * Tailwind's col-start is 1-based, so an offset of N columns means col-start-(N+1).
     */
    #[Override]
    public function getOffsetClass(string $viewport, int $offset): string
    {
        return sprintf('%s:col-start-%d', $viewport, $offset + 1);
    }

    #[Override]
    public function getRowClasses(): string
    {
        return sprintf('grid grid-cols-%d', $this->getColumnCount());
    }

    #[Override]
    public function getContainerClass(bool $fluid): string
    {
        return $fluid ? 'w-full' : 'container mx-auto';
    }

    #[Override]
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

    #[Override]
    public function getBaseWidthClass(int $width): string
    {
        return sprintf('col-span-%d', $width);
    }

    /**
     * Tailwind's col-start is 1-based, so an offset of N columns means col-start-(N+1).
     */
    #[Override]
    public function getBaseOffsetClass(int $offset): string
    {
        return sprintf('col-start-%d', $offset + 1);
    }

    #[Override]
    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::GridPlacement;
    }

    #[Override]
    public function getContentLayoutClassMap(): ContentLayoutClassMap
    {
        return ContentLayoutClassMap::tailwind();
    }

    #[Override]
    protected function formatHideClass(string $viewportKey): string
    {
        return sprintf('%s:hidden', $viewportKey);
    }

    #[Override]
    protected function formatRestoreClass(string $viewportKey): string
    {
        return sprintf('%s:block', $viewportKey);
    }
}
