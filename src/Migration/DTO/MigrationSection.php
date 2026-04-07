<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationSection
{
    /** @param list<MigrationRow> $rows */
    public function __construct(
        public string $title,
        public string $zone,
        public bool $isFluid,
        public string $extraClass,
        public int $sort,
        public array $rows,
    ) {}
}
