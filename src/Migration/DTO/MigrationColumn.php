<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

use WeDevelop\Grid\Value\GridSettings;

final readonly class MigrationColumn
{
    public function __construct(
        public GridSettings $gridSettings,
        public int $sort,
        public LegacyElement $element,
    ) {}
}
