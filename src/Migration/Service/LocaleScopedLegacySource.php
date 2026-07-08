<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * A {@see LegacyElementSource} that reads element content for one locale.
 *
 * Eligible-page discovery and grid-disabled lookups are locale-agnostic
 * (the legacy ElementalArea is shared), so they delegate unchanged; only
 * element reads are resolved per locale via the detected model.
 */
final readonly class LocaleScopedLegacySource implements LegacyElementSource
{
    /**
     * @param non-empty-string $localeCode
     * @param positive-int $localeId
     */
    public function __construct(
        private LocaleAwareLegacyReader $reader,
        private LegacyLocalisationModel $model,
        private string $localeCode,
        private int $localeId,
    ) {}

    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        return $this->reader->getEligiblePages($stage, $pageIds);
    }

    public function getElementsForArea(int $areaId, string $stage): array
    {
        return $this->reader->getElementsForAreaInLocale(
            $areaId,
            $stage,
            $this->model,
            $this->localeCode,
            $this->localeId,
        );
    }

    public function getPagesWithGridDisabled(string $stage): array
    {
        return $this->reader->getPagesWithGridDisabled($stage);
    }
}
