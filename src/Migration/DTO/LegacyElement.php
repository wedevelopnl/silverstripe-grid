<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyElement
{
    /**
     * @param positive-int $id
     * @param array<string, int> $sizeFields       e.g. ['XS' => 0, 'SM' => 0, 'MD' => 8, ...]
     * @param array<string, int> $offsetFields      e.g. ['XS' => 0, 'SM' => 0, 'MD' => 2, ...]
     * @param array<string, ?string> $visibilityFields e.g. ['XS' => 'hidden', 'MD' => 'visible', ...]
     * @param array<string, mixed> $extraData       extension hook can attach arbitrary data
     */
    public function __construct(
        public int $id,
        public string $className,
        public string $title,
        public bool $showTitle,
        public string $titleTag,
        public string $titleClass,
        public int $sort,
        public string $extraClass,
        public bool $isRow,
        public array $sizeFields,
        public array $offsetFields,
        public array $visibilityFields,
        public ?LegacyRowData $rowData = null,
        public ?LegacyMediaData $mediaData = null,
        public array $extraData = [],
    ) {}
}
