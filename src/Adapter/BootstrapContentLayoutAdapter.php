<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Bootstrap 5 implementation of content layout CSS class generation.
 *
 * Uses Bootstrap's flex utilities for ordering, alignment, and spacing.
 * Column width classes delegate to {@see GridAdapterInterface::getWidthClass()}.
 */
final class BootstrapContentLayoutAdapter implements ContentLayoutAdapterInterface
{
    public function __construct(
        private readonly GridAdapterInterface $gridAdapter,
    ) {
    }

    #[\Override]
    public function getAspectRatioClass(AspectRatio $ratio): ?string
    {
        return match ($ratio) {
            AspectRatio::Auto => null,
            AspectRatio::Square => 'ratio ratio-1x1',
            AspectRatio::FourByThree => 'ratio ratio-4x3',
            AspectRatio::SixteenByNine => 'ratio ratio-16x9',
        };
    }

    #[\Override]
    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string
    {
        return match ($alignment) {
            VerticalAlignment::Top => 'align-items-start',
            VerticalAlignment::Center => 'align-items-center',
            VerticalAlignment::Bottom => 'align-items-end',
        };
    }

    #[\Override]
    public function getMediaOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => 'order-1',
            MediaPosition::Last => 'order-2',
            MediaPosition::LastOnDesktop => sprintf('order-1 order-%s-2', $viewport),
        };
    }

    #[\Override]
    public function getContentOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => 'order-2',
            MediaPosition::Last => 'order-1',
            MediaPosition::LastOnDesktop => sprintf('order-2 order-%s-1', $viewport),
        };
    }

    #[\Override]
    public function getMediaWidthClass(int $contentColumns): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;
        $mediaColumns = $this->gridAdapter->getColumnCount() - $contentColumns;

        return $this->gridAdapter->getWidthClass($viewport, $mediaColumns);
    }

    #[\Override]
    public function getContentWidthClass(int $contentColumns): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return $this->gridAdapter->getWidthClass($viewport, $contentColumns);
    }

    #[\Override]
    public function getPaddingClass(string $direction, int $size): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;
        $prefix = $direction === 'left' ? 'ps' : 'pe';

        return sprintf('%s-%s-%d', $prefix, $viewport, $size);
    }

    #[\Override]
    public function getBaseColumnClass(): ?string
    {
        return null;
    }
}
