<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use RuntimeException;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\Value\LegacyLocalisationModel;

/**
 * Detects the legacy Fluent localisation model from database table shape.
 *
 * Pure raw-SQL inspection — never introspects legacy element classes (they
 * are uninstalled at migration time because this module conflicts with
 * dnadesign/silverstripe-elemental).
 */
final class LegacyLocalisationDetector
{
    private const string LOCALISED_TABLE = LegacyElementReader::ELEMENT_TABLE . '_Localised';

    public function detect(): LegacyLocalisationModel
    {
        // DB::table_list() keys are lowercase; DB::field_list() keys are case-preserving.
        $tables = DB::table_list();
        $hasLocalisedTable = \array_key_exists(\strtolower(self::LOCALISED_TABLE), $tables);

        $hasLocaleIdColumn = false;
        if (\array_key_exists(\strtolower(LegacyElementReader::ELEMENT_TABLE), $tables)) {
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
        return $this->detect() !== LegacyLocalisationModel::None;
    }
}
