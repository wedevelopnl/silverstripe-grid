<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class UpdateGridSettingsRequest
{
    /**
     * @param positive-int $id
     * @param non-empty-string $viewport
     * @param int<1, max> $width
     * @param int<0, max> $offset
     */
    public function __construct(
        public int $id,
        public string $viewport,
        public int $width,
        public int $offset,
        public bool $visible,
    ) {
    }
}
