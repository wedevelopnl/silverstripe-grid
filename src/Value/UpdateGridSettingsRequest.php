<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class UpdateGridSettingsRequest
{
    /**
     * @param non-empty-string $viewport
     * @param positive-int $width
     * @param int<0, max> $offset
     */
    public function __construct(
        public NodeRef $element,
        public string $viewport,
        public int $width,
        public int $offset,
        public bool $visible,
    ) {
    }
}
