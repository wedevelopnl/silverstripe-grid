<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;

/**
 * The subset of legacy reads {@see GridMigrationService} depends on.
 *
 * Implemented directly by {@see LegacyDataReader} (default/base-locale reads)
 * and by a locale-scoped decorator used by the Fluent orchestrator.
 *
 * An eligible page's `liveAreaId` is 0 when it has no live area; `fallback`
 * names the locale whose areas both stages borrow because the page has none
 * of its own in the locale being migrated.
 *
 * @phpstan-type EligiblePage array{
 *     pageId: int,
 *     areaId: int,
 *     liveAreaId: int,
 *     pageClassName: class-string,
 *     fallback?: non-empty-string,
 * }
 */
interface LegacyElementSource
{
    /**
     * @param list<int>|null $pageIds
     * @return list<EligiblePage>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array;

    /**
     * Every legacy area more than one eligible page uses, whatever pages a run selects.
     *
     * @return array<int, list<int>> area ID → page IDs, ascending
     */
    public function getSharedAreas(): array;

    /**
     * @return list<LegacyElement>
     */
    public function getElementsForArea(int $areaId, string $stage): array;

    /**
     * @return list<array{pageId: int}>
     */
    public function getPagesWithGridDisabled(string $stage): array;
}
