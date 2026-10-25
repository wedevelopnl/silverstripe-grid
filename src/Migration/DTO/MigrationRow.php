<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationRow
{
    /**
     * @param positive-int $sort
     * @param list<MigrationColumn> $columns
     * @param LegacyElement|null $legacyRow The legacy row delimiter this Row is built from;
     *     null for the implicit group before the first row. The writer fires the
     *     row field-mapping hooks only when it is set.
     */
    public function __construct(
        public string $title,
        public string $extraClass,
        public int $sort,
        public array $columns,
        public ?LegacyElement $legacyRow = null,
    ) {}
}
