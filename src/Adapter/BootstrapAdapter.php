<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

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
final class BootstrapAdapter extends AbstractGridAdapter
{
    public function __construct()
    {
        parent::__construct(
            allViewports: [
                'xs' => new Viewport('xs', 'Extra Small'),
                'sm' => new Viewport('sm', 'Small'),
                'md' => new Viewport('md', 'Medium'),
                'lg' => new Viewport('lg', 'Large'),
                'xl' => new Viewport('xl', 'Extra Large'),
                'xxl' => new Viewport('xxl', 'Extra Extra Large'),
            ],
            defaultColumns: 12,
            defaultContainerMaxWidth: 1320,
            defaultViewportKey: 'md',
        );
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
    public function getContentLayoutClassMap(): ContentLayoutClassMap
    {
        return ContentLayoutClassMap::bootstrap();
    }

    #[\Override]
    protected function formatHideClass(string $viewportKey): string
    {
        // Bootstrap: xs uses no-infix `d-none`, all others use `d-{vp}-none`
        if ($viewportKey === 'xs') {
            return 'd-none';
        }

        return sprintf('d-%s-none', $viewportKey);
    }

    #[\Override]
    protected function formatRestoreClass(string $viewportKey): string
    {
        return sprintf('d-%s-block', $viewportKey);
    }
}
