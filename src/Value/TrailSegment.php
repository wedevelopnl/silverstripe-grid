<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

/**
 * One segment of an element's location breadcrumb: a display label and an
 * optional CMS edit link. A null link renders as plain text (e.g. an orphaned
 * element, or an ancestor with no resolvable edit URL).
 */
final readonly class TrailSegment
{
    public function __construct(
        public string $label,
        public ?string $editLink,
    ) {
    }
}
