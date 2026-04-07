<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyMediaData
{
    /** @param array<string, mixed> $fields All ElementContentExtension fields as key-value pairs */
    public function __construct(
        public array $fields,
    ) {}
}
