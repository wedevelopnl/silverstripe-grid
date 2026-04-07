<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationRow
{
    /** @param list<MigrationColumn> $columns */
    public function __construct(
        public string $title,
        public string $extraClass,
        public int $sort,
        public array $columns,
    ) {}
}
