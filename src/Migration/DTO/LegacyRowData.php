<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyRowData
{
    public function __construct(
        public string $customSectionClass,
    ) {}
}
