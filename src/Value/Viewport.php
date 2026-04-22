<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * Adapter-defined viewport breakpoint.
 *
 * Each grid adapter declares its own viewport set — Bootstrap 5 has 6
 * (xs through xxl), Tailwind has 5 (sm through 2xl), Bulma uses entirely
 * different names (mobile, tablet, desktop, …). A value object lets each
 * adapter define exactly the viewports it needs rather than forcing every
 * framework into a single fixed enum.
 */
final readonly class Viewport
{
    /**
     * @param non-empty-string $key      Adapter-defined key, e.g. 'md', 'desktop', '2xl'
     * @param non-empty-string $label    Human-readable label, e.g. 'Medium', 'Desktop'
     * @param int<0, max>      $minWidth Framework breakpoint min-width in pixels.
     *                                   0 means mobile-first default (no `min-width` media
     *                                   query). Bootstrap `xs` and Bulma `mobile` use 0.
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $minWidth,
    ) {}
}
