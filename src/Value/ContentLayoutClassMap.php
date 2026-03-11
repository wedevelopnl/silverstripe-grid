<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Framework-specific CSS class mappings for content layout adapters.
 *
 * Each static factory produces the complete class map for a CSS framework.
 * The unified {@see \WeDevelop\Grid\Adapter\ContentLayoutAdapter} uses these
 * mappings to generate framework-specific output without per-framework classes.
 *
 * @param array<string, ?string> $aspectRatioClasses      AspectRatio::value => CSS class (null for Auto)
 * @param array<string, string>  $verticalAlignmentClasses VerticalAlignment::value => CSS class
 * @param array<string, string>  $paddingDirectionMap      'left'|'right' => CSS prefix
 */
final readonly class ContentLayoutClassMap
{
    /**
     * @param array<string, ?string> $aspectRatioClasses
     * @param array<string, string>  $verticalAlignmentClasses
     * @param string $orderClass1           Fixed order class for position 1
     * @param string $orderClass2           Fixed order class for position 2
     * @param string $responsiveOrderFormat sprintf format: %1$s=viewport, %2$d=order number
     * @param array<string, string>  $paddingDirectionMap
     * @param string $paddingFormat         sprintf format: %1$s=dirPrefix, %2$s=viewport, %3$d=size
     * @param ?string $baseColumnClass      Base column class (e.g. Bulma's 'column'), null if not needed
     */
    public function __construct(
        public array $aspectRatioClasses,
        public array $verticalAlignmentClasses,
        public string $orderClass1,
        public string $orderClass2,
        public string $responsiveOrderFormat,
        public array $paddingDirectionMap,
        public string $paddingFormat,
        public ?string $baseColumnClass,
    ) {
    }

    public static function bootstrap(): self
    {
        return new self(
            aspectRatioClasses: [
                AspectRatio::Auto->value => null,
                AspectRatio::Square->value => 'ratio ratio-1x1',
                AspectRatio::FourByThree->value => 'ratio ratio-4x3',
                AspectRatio::SixteenByNine->value => 'ratio ratio-16x9',
            ],
            verticalAlignmentClasses: [
                VerticalAlignment::Top->value => 'align-items-start',
                VerticalAlignment::Center->value => 'align-items-center',
                VerticalAlignment::Bottom->value => 'align-items-end',
            ],
            orderClass1: 'order-1',
            orderClass2: 'order-2',
            responsiveOrderFormat: 'order-%1$s-%2$d',
            paddingDirectionMap: ['left' => 'ps', 'right' => 'pe'],
            paddingFormat: '%1$s-%2$s-%3$d',
            baseColumnClass: null,
        );
    }

    public static function tailwind(): self
    {
        return new self(
            aspectRatioClasses: [
                AspectRatio::Auto->value => null,
                AspectRatio::Square->value => 'aspect-square',
                AspectRatio::FourByThree->value => 'aspect-[4/3]',
                AspectRatio::SixteenByNine->value => 'aspect-video',
            ],
            verticalAlignmentClasses: [
                VerticalAlignment::Top->value => 'items-start',
                VerticalAlignment::Center->value => 'items-center',
                VerticalAlignment::Bottom->value => 'items-end',
            ],
            orderClass1: 'order-1',
            orderClass2: 'order-2',
            responsiveOrderFormat: '%1$s:order-%2$d',
            paddingDirectionMap: ['left' => 'pl', 'right' => 'pr'],
            paddingFormat: '%2$s:%1$s-%3$d',
            baseColumnClass: null,
        );
    }

    public static function bulma(): self
    {
        return new self(
            aspectRatioClasses: [
                AspectRatio::Auto->value => null,
                AspectRatio::Square->value => 'is-1by1',
                AspectRatio::FourByThree->value => 'is-4by3',
                AspectRatio::SixteenByNine->value => 'is-16by9',
            ],
            verticalAlignmentClasses: [
                VerticalAlignment::Top->value => 'is-flex-start',
                VerticalAlignment::Center->value => 'is-vcentered',
                VerticalAlignment::Bottom->value => 'is-flex-end',
            ],
            orderClass1: 'has-order-1',
            orderClass2: 'has-order-2',
            responsiveOrderFormat: 'has-order-%2$d-%1$s',
            paddingDirectionMap: ['left' => 'pl', 'right' => 'pr'],
            paddingFormat: '%1$s-%3$d-%2$s',
            baseColumnClass: 'column',
        );
    }
}
