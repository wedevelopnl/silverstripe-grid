<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Adapter;

/**
 * Tailwind CSS grid preset using utility classes for a 12-column CSS Grid layout.
 *
 * Five breakpoints matching Tailwind v3/v4 defaults: sm (640px), md (768px),
 * lg (1024px), xl (1280px), 2xl (1536px). No base viewport — all breakpoints
 * use the responsive `{viewport}:` prefix format.
 *
 * Uses grid-placement offset strategy (`col-start-N`, 1-based) instead of
 * margin-based offsets.
 *
 * Override any property via project YAML:
 * ```yaml
 * WeDevelop\Grid\Adapter\TailwindAdapter:
 *   total_columns: 16
 *   enabled_viewports: [sm, md, lg]
 * ```
 */
final class TailwindAdapter extends GridAdapter
{
    // ─── Grid topology ──────────────────────────────────────────────

    /** @var array<non-empty-string, array{label: non-empty-string, min_width: int<0, max>}> */
    private static array $viewport_definitions = [
        'sm'  => ['label' => 'Small',       'min_width' => 640],
        'md'  => ['label' => 'Medium',      'min_width' => 768],
        'lg'  => ['label' => 'Large',       'min_width' => 1024],
        'xl'  => ['label' => 'Extra Large', 'min_width' => 1280],
        '2xl' => ['label' => '2X Large',    'min_width' => 1536],
    ];

    /** @var positive-int */
    private static int $total_columns = 12;

    /** @var positive-int */
    private static int $container_max_width = 1536;

    private static string $default_viewport = 'sm';

    // ─── Width & offset formats ─────────────────────────────────────

    private static string $base_width_format = 'col-span-%d';

    private static string $responsive_width_format = '%1$s:col-span-%2$d';

    private static string $base_offset_format = 'col-start-%d';

    private static string $responsive_offset_format = '%1$s:col-start-%2$d';

    private static int $offset_adjustment = 1;

    // ─── Visibility formats ─────────────────────────────────────────

    private static string $responsive_hide_format = '%s:hidden';

    private static string $responsive_restore_format = '%s:block';

    // ─── Container & structure ───────────────────────────────────────

    private static string $row_class_format = 'grid grid-cols-%d';

    private static string $container_class = 'container mx-auto';

    private static string $fluid_container_class = 'w-full';

    /** @var array<string, string> */
    private static array $title_class_options = [
        'text-4xl' => 'Heading 1',
        'text-3xl' => 'Heading 2',
        'text-2xl' => 'Heading 3',
        'text-xl' => 'Heading 4',
        'text-lg' => 'Heading 5',
        'text-base' => 'Heading 6',
    ];

    private static string $offset_strategy = 'grid-placement';

    // ─── Content layout ─────────────────────────────────────────────

    /** @var array<string, ?string> */
    private static array $aspect_ratio_classes = [
        'auto' => null,
        '1x1' => 'aspect-square',
        '4x3' => 'aspect-[4/3]',
        '16x9' => 'aspect-video',
    ];

    /** @var array<string, string> */
    private static array $vertical_alignment_classes = [
        'top' => 'items-start',
        'center' => 'items-center',
        'bottom' => 'items-end',
    ];

    private static string $order_class_format = 'order-%d';

    private static string $responsive_order_format = '%1$s:order-%2$d';

    /** @var array<string, string> */
    private static array $padding_direction_map = [
        'left' => 'pl',
        'right' => 'pr',
    ];

    private static string $padding_format = '%2$s:%1$s-%3$d';

    private static ?string $base_column_class = null;
}
