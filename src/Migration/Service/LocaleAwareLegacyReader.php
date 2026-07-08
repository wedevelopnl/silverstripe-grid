<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * The reader surface a locale-scoped source needs: the locale-agnostic
 * {@see LegacyElementSource} reads plus per-locale element reads. Implemented by
 * {@see LegacyDataReader}; depending on this port (rather than the concrete
 * facade) lets {@see LocaleScopedLegacySource} be unit-tested with a stub.
 */
interface LocaleAwareLegacyReader extends LegacyElementSource
{
    /**
     * @param non-empty-string $localeCode
     * @param positive-int $localeId
     * @return list<LegacyElement>
     */
    public function getElementsForAreaInLocale(
        int $areaId,
        string $stage,
        LegacyLocalisationModel $model,
        string $localeCode,
        int $localeId,
    ): array;
}
