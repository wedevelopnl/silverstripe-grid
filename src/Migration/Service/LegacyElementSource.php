<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * The subset of legacy reads {@see GridMigrationService} depends on.
 *
 * Implemented directly by {@see LegacyDataReader} (default/base-locale reads)
 * and by a locale-scoped decorator used by the Fluent orchestrator.
 */
interface LegacyElementSource
{
    /**
     * @param list<int>|null $pageIds
     * @return list<array{pageId: int, areaId: int, pageClassName: class-string}>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array;

    /**
     * @return list<LegacyElement>
     */
    public function getElementsForArea(int $areaId, string $stage): array;

    /**
     * @return list<array{pageId: int}>
     */
    public function getPagesWithGridDisabled(string $stage): array;
}
