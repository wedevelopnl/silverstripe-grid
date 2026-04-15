<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class CreateElementRequest
{
    /**
     * @param positive-int|null $insertAfterElementID
     * @param non-empty-string $zone
     */
    public function __construct(
        public ContainerType $containerType,
        public NodeRef $parent,
        public ?int $insertAfterElementID,
        public string $zone,
    ) {
    }
}
