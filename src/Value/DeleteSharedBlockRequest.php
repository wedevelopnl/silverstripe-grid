<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class DeleteSharedBlockRequest
{
    /** @param positive-int $blockId */
    public function __construct(
        public int $blockId,
        public SharedBlockDeleteMode $mode,
    ) {
    }
}
