<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

/**
 * Bootstrap 5 Flexbox grid preset.
 *
 * Six breakpoints: xs (mobile-first default, no viewport infix), sm (576px),
 * md (768px), lg (992px), xl (1200px), xxl (1400px).
 *
 * Override any property via project YAML:
 * ```yaml
 * WeDevelop\Grid\Adapter\BootstrapAdapter:
 *   total_columns: 16
 *   enabled_viewports: [sm, md, lg]
 * ```
 */
final class BootstrapAdapter extends GridAdapter
{
    /** @var array<non-empty-string, array{label: non-empty-string, min_width: int<0, max>}> */
    private static array $viewport_definitions = [
        'xs'  => ['label' => 'Extra Small',       'min_width' => 0],
        'sm'  => ['label' => 'Small',             'min_width' => 576],
        'md'  => ['label' => 'Medium',            'min_width' => 768],
        'lg'  => ['label' => 'Large',             'min_width' => 992],
        'xl'  => ['label' => 'Extra Large',       'min_width' => 1200],
        'xxl' => ['label' => 'Extra Extra Large', 'min_width' => 1400],
    ];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1320;

    private static string $default_viewport = 'md';

    private static ?string $base_viewport_key = 'xs';

    private static string $base_width_format = 'col-%d';

    private static string $responsive_width_format = 'col-%1$s-%2$d';

    private static string $base_offset_format = 'offset-%d';

    private static string $responsive_offset_format = 'offset-%1$s-%2$d';

    private static int $offset_adjustment = 0;

    private static string $base_hide_class = 'd-none';

    private static string $responsive_hide_format = 'd-%s-none';

    private static string $responsive_restore_format = 'd-%s-block';

    private static string $row_class_format = 'row';

    private static string $container_class = 'container';

    private static string $fluid_container_class = 'container-fluid';

    /** @var array<string, string> */
    private static array $title_class_options = [
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

    private static string $offset_strategy = 'margin';

    /** @var array<string, ?string> */
    private static array $aspect_ratio_classes = [
        'auto' => null,
        '1x1' => 'ratio ratio-1x1',
        '4x3' => 'ratio ratio-4x3',
        '16x9' => 'ratio ratio-16x9',
    ];

    /** @var array<string, string> */
    private static array $vertical_alignment_classes = [
        'top' => 'align-items-start',
        'center' => 'align-items-center',
        'bottom' => 'align-items-end',
    ];

    private static string $order_class_format = 'order-%d';

    private static string $responsive_order_format = 'order-%1$s-%2$d';

    /** @var array<string, string> */
    private static array $padding_direction_map = [
        'left' => 'ps',
        'right' => 'pe',
    ];

    private static string $padding_format = '%1$s-%2$s-%3$d';

    private static ?string $base_column_class = null;
}
