<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class PlaceSharedBlockRequest
{
    /**
     * @param positive-int $blockId
     * @param positive-int|null $insertAfterElementID
     */
    public function __construct(
        public int $blockId,
        public NodeRef $parent,
        public string $zone,
        public ?int $insertAfterElementID,
    ) {
    }
}
