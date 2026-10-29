<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

/**
 * A page with legacy content blocks that the migration leaves without a grid,
 * because its current type no longer carries them.
 */
final readonly class SkippedPage
{
    /**
     * @param class-string $pageClassName The page's current (draft) type
     * @param non-empty-string $reason
     */
    public function __construct(
        public int $pageId,
        public string $title,
        public string $pageClassName,
        public string $reason,
    ) {}
}
