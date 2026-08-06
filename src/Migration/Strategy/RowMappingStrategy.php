<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationSection;

interface RowMappingStrategy
{
    /**
     * Map a page's flat legacy element list onto the Section → Row → Column DTO
     * tree that {@see \WeDevelop\Grid\Migration\Service\DraftHierarchyWriter}
     * writes.
     *
     * @param list<LegacyElement> $elements Flat sorted element list
     * @param int $pageId Unused by both bundled strategies — the parent
     *     relationship is established later, by DraftHierarchyWriter::createSection().
     *     Kept in the signature for implementations that need it; scheduled for
     *     removal in 7.0.0.
     * @param string $zone Target zone
     * @return list<MigrationSection>
     */
    public function buildHierarchy(array $elements, int $pageId, string $zone): array;
}
