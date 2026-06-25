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
    public function detect(): LegacyLocalisationModel
    {
        // DB::table_list() keys are lowercase; DB::field_list() keys are case-preserving.
        $hasLocalisedTable = \array_key_exists('baseelement_localised', DB::table_list());

        $hasLocaleIdColumn = false;
        if (\array_key_exists('baseelement', DB::table_list())) {
            $hasLocaleIdColumn = \array_key_exists('LocaleID', DB::field_list('BaseElement'));
        }

        if ($hasLocalisedTable && $hasLocaleIdColumn) {
            throw new RuntimeException(
                'Ambiguous legacy localisation: both BaseElement_Localised and '
                . 'BaseElement.LocaleID exist. This mixed Fluent configuration is unsupported.',
            );
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
