<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

use Override;
use WeDevelop\Grid\Contract\ContentLayoutAdapterInterface;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Value\AspectRatio;
use WeDevelop\Grid\Value\ContentLayoutClassMap;
use WeDevelop\Grid\Value\MediaPosition;
use WeDevelop\Grid\Value\VerticalAlignment;

/**
 * Data-driven content layout adapter that replaces per-framework classes.
 *
 * All framework-specific CSS strings live in {@see ContentLayoutClassMap},
 * provided by the active {@see GridAdapterInterface}. This class contains
 * only the shared logic: enum lookups, order inversion, sprintf formatting,
 * and width delegation to the grid adapter.
 */
final readonly class ContentLayoutAdapter implements ContentLayoutAdapterInterface
{
    private ContentLayoutClassMap $classMap;

    public function __construct(
        private GridAdapterInterface $gridAdapter,
    ) {
        $this->classMap = $gridAdapter->getContentLayoutClassMap();
    }

    #[Override]
    public function getAspectRatioClass(AspectRatio $ratio): ?string
    {
        return $this->classMap->aspectRatioClasses[$ratio->value];
    }

    #[Override]
    public function getVerticalAlignmentClass(VerticalAlignment $alignment): string
    {
        return $this->classMap->verticalAlignmentClasses[$alignment->value];
    }

    #[Override]
    public function getMediaOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => $this->classMap->orderClass1,
            MediaPosition::Last => $this->classMap->orderClass2,
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                $this->classMap->orderClass1,
                sprintf($this->classMap->responsiveOrderFormat, $viewport, 2),
            ),
        };
    }

    #[Override]
    public function getContentOrderClasses(MediaPosition $position): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;

        return match ($position) {
            MediaPosition::First => $this->classMap->orderClass2,
            MediaPosition::Last => $this->classMap->orderClass1,
            MediaPosition::LastOnDesktop => sprintf(
                '%s %s',
                $this->classMap->orderClass2,
                sprintf($this->classMap->responsiveOrderFormat, $viewport, 1),
            ),
        };
    }

    #[Override]
    public function getMediaWidthClass(int $contentColumns): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;
        /** @var positive-int $mediaColumns Caller guarantees contentColumns < columnCount */
        $mediaColumns = $this->gridAdapter->getColumnCount() - $contentColumns;

        return $this->gridAdapter->getWidthClass($viewport, $mediaColumns);
    }

    #[Override]
    public function getContentWidthClass(int $contentColumns): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;
        /** @var positive-int $contentColumns Caller guarantees > 0 */

        return $this->gridAdapter->getWidthClass($viewport, $contentColumns);
    }

    #[Override]
    public function getPaddingClass(string $direction, int $size): string
    {
        $viewport = $this->gridAdapter->getDefaultViewport()->key;
        $prefix = $this->classMap->paddingDirectionMap[$direction];

        return sprintf($this->classMap->paddingFormat, $prefix, $viewport, $size);
    }

    #[Override]
    public function getBaseColumnClass(): ?string
    {
        return $this->classMap->baseColumnClass;
    }
}
