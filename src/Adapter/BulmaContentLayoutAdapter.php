<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Bulma CSS implementation of content layout CSS class generation.
 *
 * Uses Bulma's modifier classes for ordering, alignment, and spacing.
 * Column width classes delegate to {@see GridAdapterInterface::getWidthClass()}.
 */
final class BulmaContentLayoutAdapter implements ContentLayoutAdapterInterface
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
            AspectRatio::Square => 'is-1by1',
            AspectRatio::FourByThree => 'is-4by3',
            AspectRatio::SixteenByNine => 'is-16by9',
        };
    }

    #[\Override]
    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string
    {
        return match ($alignment) {
            VerticalAlignment::Top => 'is-flex-start',
            VerticalAlignment::Center => 'is-vcentered',
            VerticalAlignment::Bottom => 'is-flex-end',
        };
    }

    #[\Override]
    public function getMediaOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => 'has-order-1',
            MediaPosition::Last => 'has-order-2',
            MediaPosition::LastOnDesktop => sprintf('has-order-1 has-order-2-%s', $viewport),
        };
    }

    #[\Override]
    public function getContentOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => 'has-order-2',
            MediaPosition::Last => 'has-order-1',
            MediaPosition::LastOnDesktop => sprintf('has-order-2 has-order-1-%s', $viewport),
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
        $prefix = $direction === 'left' ? 'pl' : 'pr';

        return sprintf('%s-%d-%s', $prefix, $size, $viewport);
    }

    #[\Override]
    public function getBaseColumnClass(): string
    {
        return 'column';
    }
}
