<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Value;

/**
 * How the legacy Elemental content is localised under Fluent, detected from
 * database table shape (legacy element classes are uninstalled at migration
 * time, so Config introspection is impossible — see the design spec).
 */
enum LegacyLocalisationModel
{
    /** Element_Localised present: shared rows, per-locale field values. */
    case FieldLocalised;

    /** Element.LocaleID present: separate element rows per locale. */
    case Isolated;

    /** No localisation artifacts: single-locale legacy content. */
    case None;
}
