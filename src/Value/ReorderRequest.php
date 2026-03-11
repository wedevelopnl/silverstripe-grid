<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class ReorderRequest
{
    /**
     * @param positive-int $elementID
     * @param positive-int $targetParentId
     * @param positive-int|null $afterElementID
     */
    public function __construct(
        public int $elementID,
        public int $targetParentId,
        public ?int $afterElementID,
    ) {
    }
}
