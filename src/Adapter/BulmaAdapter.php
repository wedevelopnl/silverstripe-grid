<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

/**
 * Bulma CSS framework grid preset (Flexbox column system).
 *
 * Five breakpoints: mobile (unsuffixed default), tablet (769px), desktop (1024px),
 * widescreen (1216px), fullhd (1408px). Mobile is the base viewport — width/offset
 * classes use `is-{n}` without a viewport suffix.
 *
 * Requires the `column` base class on grid columns.
 *
 * Override any property via project YAML:
 * ```yaml
 * WeDevelop\Grid\Adapter\BulmaAdapter:
 *   total_columns: 16
 *   enabled_viewports: [tablet, desktop, widescreen]
 * ```
 */
final class BulmaAdapter extends GridAdapter
{
    /** @var array<non-empty-string, array{label: non-empty-string, min_width: int<0, max>}> */
    private static array $viewport_definitions = [
        'mobile'     => ['label' => 'Mobile',     'min_width' => 0],
        'tablet'     => ['label' => 'Tablet',     'min_width' => 769],
        'desktop'    => ['label' => 'Desktop',    'min_width' => 1024],
        'widescreen' => ['label' => 'Widescreen', 'min_width' => 1216],
        'fullhd'     => ['label' => 'Full HD',    'min_width' => 1408],
    ];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1344;

    private static string $default_viewport = 'desktop';

    private static ?string $base_viewport_key = 'mobile';

    private static string $base_width_format = 'is-%d';

    private static string $responsive_width_format = 'is-%2$d-%1$s';

    private static string $base_offset_format = 'is-offset-%d';

    private static string $responsive_offset_format = 'is-offset-%2$d-%1$s';

    private static int $offset_adjustment = 0;

    // Bulma ships `-only` hide helpers for its middle breakpoints, but the two
    // outermost ones have no `-only` variant: `is-hidden-mobile` already means
    // "up to 768px" and `is-hidden-fullhd` already means "from 1408px", which is
    // the whole of the largest breakpoint. Both are viewport-scoped as-is.
    // https://bulma.io/documentation/helpers/visibility-helpers/
    private static string $base_hide_class = 'is-hidden-mobile';

    private static string $responsive_hide_format = 'is-hidden-%s-only';

    /** @var array<string, string> */
    private static array $hide_class_overrides = [
        'fullhd' => 'is-hidden-fullhd',
    ];

    // Bulma has no symmetric `is-block-{viewport}` utility. Every hide class above
    // is scoped to its own viewport, so no restore class is needed.
    private static string $responsive_restore_format = '';

    private static string $row_class_format = 'columns is-multiline';

    private static string $container_class = 'container';

    private static string $fluid_container_class = 'container is-fluid';

    /** @var array<string, string> */
    private static array $title_class_options = [
        'is-1' => 'Title 1',
        'is-2' => 'Title 2',
        'is-3' => 'Title 3',
        'is-4' => 'Title 4',
        'is-5' => 'Title 5',
        'is-6' => 'Title 6',
    ];

    private static string $offset_strategy = 'margin';

    /** @var array<string, ?string> */
    private static array $aspect_ratio_classes = [
        'auto' => null,
        '1x1' => 'is-1by1',
        '4x3' => 'is-4by3',
        '16x9' => 'is-16by9',
    ];

    // `.columns` is a flex container, so Bulma's generic align-items helpers apply.
    // `is-vcentered` is the columns-specific alias for the center case only —
    // Bulma ships no `.columns` modifier for top or bottom, so the whole map uses
    // one family. https://bulma.io/documentation/helpers/flexbox-helpers/
    /** @var array<string, string> */
    private static array $vertical_alignment_classes = [
        'top' => 'is-align-items-flex-start',
        'center' => 'is-align-items-center',
        'bottom' => 'is-align-items-flex-end',
    ];

    // Bulma ships no order utilities at all. These classes are a module
    // convention: a Bulma project using the side-by-side media layout must supply
    // the `has-order-*` CSS itself (see docs/architecture/grid-adapter.md), or
    // override both formats with its own utility names.
    private static string $order_class_format = 'has-order-%d';

    private static string $responsive_order_format = 'has-order-%2$d-%1$s';

    /** @var array<string, string> */
    private static array $padding_direction_map = [
        'left' => 'pl',
        'right' => 'pr',
    ];

    private static string $padding_format = '%1$s-%3$d-%2$s';

    private static ?string $base_column_class = 'column';
}
