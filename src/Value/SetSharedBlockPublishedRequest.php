<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class SetSharedBlockPublishedRequest
{
    /** @param positive-int $blockId */
    public function __construct(
        public int $blockId,
        public bool $published,
    ) {
    }
}
