<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * A {@see LegacyElementSource} that reads one locale's legacy content.
 *
 * Pages resolve their area per locale and stage where the page table
 * localised it, falling back along the locale's chain; a page table sharing
 * one area across locales joins the default locale's pass, and every pass
 * when the elements themselves are localised. Element reads are resolved per
 * locale via the detected model. Grid-disabled lookups stay locale-agnostic.
 */
final readonly class LocaleScopedLegacySource implements LegacyElementSource
{
    /**
     * @param non-empty-string $localeCode
     * @param positive-int $localeId
     * @param non-empty-list<non-empty-string> $chain The locale, then its Fluent fallbacks in order
     */
    public function __construct(
        private LegacyDataReader $reader,
        private LegacyLocalisationModel $model,
        private string $localeCode,
        private int $localeId,
        private array $chain,
        private bool $isDefault,
    ) {}

    /**
     * Draft-based, whatever `$stage` says: each page carries its live area too.
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        return $this->reader->getEligiblePagesInLocale($this->chain, $this->isDefault, $this->migratesSharedAreas(), $pageIds);
    }

    /**
     * Reported from the default locale's pass only, so a skipped page is
     * listed once per run rather than once per locale.
     */
    public function getSkippedPages(?array $pageIds = null): array
    {
        if (!$this->isDefault) {
            return [];
        }

        return $this->reader->getSkippedPagesInLocale($this->chain, true, $this->migratesSharedAreas(), $pageIds);
    }

    public function getSharedAreas(): array
    {
        return $this->reader->getSharedAreasInLocale($this->chain, $this->isDefault, $this->migratesSharedAreas());
    }

    /**
     * An area every locale shares holds one locale's blocks unless the
     * elements carry their own translations.
     */
    private function migratesSharedAreas(): bool
    {
        return $this->isDefault || $this->model !== LegacyLocalisationModel::None;
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
