<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\OffsetStrategy;
use WeDevelop\Grid\Value\Viewport;

/**
 * Grid adapter for the Bulma CSS framework (Flexbox column system).
 *
 * Implements Bulma's responsive column classes using its five default
 * breakpoints: mobile, tablet (769px), desktop (1024px), widescreen
 * (1216px), and fullhd (1408px).
 *
 * Bulma treats mobile as the unsuffixed default — width classes use
 * `is-{n}` instead of `is-{n}-mobile`, and offset classes follow the
 * same pattern. All other viewports append `-{viewport}` as a suffix.
 */
final class BulmaAdapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                'mobile' => new Viewport('mobile', 'Mobile'),
                'tablet' => new Viewport('tablet', 'Tablet'),
                'desktop' => new Viewport('desktop', 'Desktop'),
                'widescreen' => new Viewport('widescreen', 'Widescreen'),
                'fullhd' => new Viewport('fullhd', 'Full HD'),
            ],
            defaultColumns: 12,
            defaultContainerMaxWidth: 1344,
            defaultViewportKey: 'desktop',
        );
    }

    #[\Override]
    public function getWidthClass(string $viewport, int $width): string
    {
        if ($viewport === 'mobile') {
            return sprintf('is-%d', $width);
        }

        return sprintf('is-%d-%s', $width, $viewport);
    }

    #[\Override]
    public function getOffsetClass(string $viewport, int $offset): string
    {
        if ($viewport === 'mobile') {
            return sprintf('is-offset-%d', $offset);
        }

        return sprintf('is-offset-%d-%s', $offset, $viewport);
    }

    #[\Override]
    public function getRowClasses(): string
    {
        return 'columns is-multiline';
    }

    #[\Override]
    public function getContainerClass(bool $fluid): string
    {
        if ($fluid) {
            return 'container is-fluid';
        }

        return 'container';
    }

    #[\Override]
    public function getTitleClassOptions(): array
    {
        return [
            'is-1' => 'Title 1',
            'is-2' => 'Title 2',
            'is-3' => 'Title 3',
            'is-4' => 'Title 4',
            'is-5' => 'Title 5',
            'is-6' => 'Title 6',
        ];
    }

    #[\Override]
    public function getBaseWidthClass(int $width): string
    {
        return $this->getWidthClass('mobile', $width);
    }

    #[\Override]
    public function getBaseOffsetClass(int $offset): string
    {
        return $this->getOffsetClass('mobile', $offset);
    }

    #[\Override]
    public function getOffsetStrategy(): OffsetStrategy
    {
        return OffsetStrategy::Margin;
    }

    #[\Override]
    public function getContentLayoutClassMap(): ContentLayoutClassMap
    {
        return ContentLayoutClassMap::bulma();
    }

    #[\Override]
    protected function formatHideClass(string $viewportKey): string
    {
        return sprintf('is-hidden-%s', $viewportKey);
    }

    #[\Override]
    protected function formatRestoreClass(string $viewportKey): string
    {
        return sprintf('is-block-%s', $viewportKey);
    }
}
