<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class CreateElementRequest
{
    /**
     * @param positive-int $parentId
     * @param positive-int|null $insertAfterElementID
     */
    public function __construct(
        public ContainerType $containerType,
        public int $parentId,
        public ?int $insertAfterElementID,
        public string $zone,
    ) {
    }
}
