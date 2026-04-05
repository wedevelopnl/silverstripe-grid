<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use TractorCow\Fluent\Model\Delete\DeleteLocalisationPolicy;

// This class extends a Fluent class that may not be installed.
// The class_exists guard prevents fatal errors when Fluent is absent.
// The YAML config (_config/fluent.yml) ensures this class is only
// registered via DI when Fluent is installed.
if (!class_exists(DeleteLocalisationPolicy::class)) {
    return;
}

use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;

/**
 * Extends the standard Fluent deletion policy to also remove grid elements
 * when clearing a locale from a page.
 *
 * Grid elements use FluentIsolatedExtension (LocaleID FK on base table),
 * which the standard policy does not handle — it only deletes _Localised
 * table entries for FluentExtension records.
 *
 * Registered via DI in _config/fluent.yml to replace the standard policy.
 */
class GridAwareDeleteLocalisationPolicy extends DeleteLocalisationPolicy
{
    /**
     * @param DataObject $record The record whose locale is being cleared
     * @return bool Whether other localisations remain (blocking upstream deletion)
     */
    public function delete(DataObject $record): bool
    {
        $result = parent::delete($record);

        if (!$record->hasExtension(GridPageExtension::class)) {
            return $result;
        }

        if (!GridElement::has_extension(FluentIsolatedExtension::class)) {
            return $result;
        }

        // FluentIsolatedExtension::augmentSQL filters by current locale,
        // so Sections() returns only the current locale's Sections.
        // cascade_deletes on Section handles Rows → Columns → ContentElements.
        foreach ($record->Sections() as $section) {
            $section->delete();
        }

        return $result;
    }
}
