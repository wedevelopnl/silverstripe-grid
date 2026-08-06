<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use SilverStripe\Core\Extensible;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyMediaData;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Public entry point for reading legacy elemental data from the old database tables.
 *
 * The old dnadesign/silverstripe-elemental tables (BaseElement, ElementRow,
 * ElementContent, ElementalArea) remain in the database after the module is
 * removed. This facade composes two collaborators and delegates to them:
 * - {@see LegacyPageDiscovery} — eligible-page and grid-toggle discovery
 * - {@see LegacyElementReader} — element hydration with batched companion reads
 *
 * It remains the {@see LegacyElementSource} implementation so all existing
 * `new LegacyDataReader()` call sites keep working unchanged, and it owns the
 * `updateLegacyElements` extension hook: the hook fires exactly once per public
 * element read from here, never from the inner reader.
 *
 * Supports two migration sources:
 * - WeDevelop ElementalGrid: pages have both UseElementalGrid and ElementalAreaID columns
 * - Plain dnadesign/silverstripe-elemental: pages have only ElementalAreaID (no UseElementalGrid)
 *
 * When UseElementalGrid is absent, all pages with ElementalAreaID > 0 are eligible.
 */
final class LegacyDataReader implements LegacyElementSource
{
    use Extensible;

    private readonly LegacyPageDiscovery $discovery;

    private readonly LegacyElementReader $elementReader;

    public function __construct()
    {
        $this->discovery = new LegacyPageDiscovery();
        $this->elementReader = new LegacyElementReader();
    }

    /**
     * @param list<int>|null $pageIds
     * @return list<array{pageId: int, areaId: int, pageClassName: class-string}>
     */
    public function getEligiblePages(string $stage, ?array $pageIds = null): array
    {
        return $this->discovery->getEligiblePages($stage, $pageIds);
    }

    /**
     * @return list<array{pageId: int}>
     */
    public function getPagesWithGridDisabled(string $stage): array
    {
        return $this->discovery->getPagesWithGridDisabled($stage);
    }

    /**
     * Load all legacy elements for an ElementalArea, sorted by position.
     *
     * After hydration the `updateLegacyElements` extension hook is invoked so
     * consuming projects can attach additional data or filter elements.
     *
     * @return list<LegacyElement>
     */
    public function getElementsForArea(int $areaId, string $stage): array
    {
        $elements = $this->elementReader->getElementsForArea($areaId, $stage);

        $this->extend('updateLegacyElements', $elements, $areaId, $stage);

        /** @var list<LegacyElement> $elements */
        return $elements;
    }

    /**
     * Locale-aware variant of {@see getElementsForArea()}.
     *
     * Fires the `updateLegacyElements` hook exactly once per call (the None
     * model delegates internally to the inner reader's base read, which does not
     * fire — so the single fire here preserves the historical one-fire behaviour).
     *
     * @param non-empty-string $localeCode Fluent locale code (e.g. 'nl_NL')
     * @param positive-int $localeId Fluent Locale record ID (used by the Isolated model)
     * @return list<LegacyElement>
     */
    public function getElementsForAreaInLocale(
        int $areaId,
        string $stage,
        LegacyLocalisationModel $model,
        string $localeCode,
        int $localeId,
    ): array {
        $elements = $this->elementReader->getElementsForAreaInLocale(
            $areaId,
            $stage,
            $model,
            $localeCode,
            $localeId,
        );

        $this->extend('updateLegacyElements', $elements, $areaId, $stage);

        /** @var list<LegacyElement> $elements */
        return $elements;
    }

    /**
     * @deprecated 6.0.0 Delegates to the deprecated
     *     {@see LegacyElementReader::getRowData()}; the hydration path batches
     *     these reads. Will be removed in 7.0.0.
     */
    public function getRowData(int $elementId, string $stage): ?LegacyRowData
    {
        return $this->elementReader->getRowData($elementId, $stage);
    }

    /**
     * Fetch content media extension fields from the ElementContent table.
     *
     * @deprecated 6.0.0 Delegates to the deprecated
     *     {@see LegacyElementReader::getContentMediaData()}; the hydration path
     *     batches these reads. Will be removed in 7.0.0.
     */
    public function getContentMediaData(int $elementId, string $stage): ?LegacyMediaData
    {
        return $this->elementReader->getContentMediaData($elementId, $stage);
    }
}
