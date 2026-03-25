<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

final readonly class UpdateGridSettingsRequest
{
    /**
     * @param positive-int $id
     * @param non-empty-string $viewport
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
