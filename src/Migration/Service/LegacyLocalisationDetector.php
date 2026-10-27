<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Detects the legacy Fluent localisation from database table shape, on two
 * independent axes: how the elements are localised ({@see detect()}) and
 * which page tables point each locale at its own area
 * ({@see perLocaleAreaTables()}).
 *
 * Pure raw-SQL inspection — never introspects legacy element classes (they
 * are uninstalled at migration time because this module conflicts with
 * dnadesign/silverstripe-elemental).
 */
final readonly class LegacyLocalisationDetector
{
    private const string LOCALISED_TABLE = LegacyElementReader::ELEMENT_TABLE . '_Localised';

    private LegacyPageDiscovery $discovery;

    public function __construct()
    {
        $this->discovery = new LegacyPageDiscovery();
    }

    public function detect(): LegacyLocalisationModel
    {
        $hasLocalisedTable = LegacyTables::exists(self::LOCALISED_TABLE);

        $hasLocaleIdColumn = false;
        if (LegacyTables::exists(LegacyElementReader::ELEMENT_TABLE)) {
            // DB::field_list() keys are case-preserving.
            $hasLocaleIdColumn = \array_key_exists('LocaleID', DB::field_list(LegacyElementReader::ELEMENT_TABLE));
        }

        if ($hasLocalisedTable && $hasLocaleIdColumn) {
            throw new RuntimeException(\sprintf(
                'Ambiguous legacy localisation: both %s and %s.LocaleID exist. '
                . 'This mixed Fluent configuration is unsupported.',
                self::LOCALISED_TABLE,
                LegacyElementReader::ELEMENT_TABLE,
            ));
        }

        if ($hasLocalisedTable) {
            return LegacyLocalisationModel::FieldLocalised;
        }

        if ($hasLocaleIdColumn) {
            return LegacyLocalisationModel::Isolated;
        }

        return LegacyLocalisationModel::None;
    }

    public function hasLocalisedContent(): bool
    {
        return $this->detect() !== LegacyLocalisationModel::None || $this->perLocaleAreaTables() !== [];
    }

    /**
     * Page tables whose `<Table>_Localised` companion carries ElementalAreaID.
     *
     * @return list<string>
     */
    public function perLocaleAreaTables(): array
    {
        return $this->discovery->getPerLocaleAreaTables();
    }

    /**
     * Page tables whose per-locale ElementalAreaID dev/build moved to
     * `_obsolete_<Table>_Localised`.
     *
     * @return list<string>
     */
    public function setAsideAreaTables(): array
    {
        return $this->discovery->getSetAsideAreaTables();
    }
}
