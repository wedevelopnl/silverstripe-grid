<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationSection
{
    /**
     * @param positive-int $sort
     * @param list<MigrationRow> $rows
     * @param LegacyElement|null $legacyRow The legacy row delimiter this Section's own fields
     *     are taken from; null when there is none. The writer fires the section
     *     field-mapping hooks only when it is set.
     */
    public function __construct(
        public string $title,
        public string $zone,
        public string $extraClass,
        public int $sort,
        public array $rows,
        public ?LegacyElement $legacyRow = null,
    ) {}
}
