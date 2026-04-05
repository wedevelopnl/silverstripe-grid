<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Delete\DeleteLocalisationPolicy;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;

/**
 * Wraps the standard Fluent deletion policy and also removes grid elements
 * when clearing a locale from a page.
 *
 * Grid elements use FluentIsolatedExtension (LocaleID FK on base table),
 * which the standard policy does not handle — it only deletes _Localised
 * table entries for FluentExtension records.
 *
 * Uses composition instead of inheritance to avoid PHP resolving
 * the Fluent parent class at file-load time (which would fail when
 * Fluent is not installed and the class manifest scans this file).
 *
 * Registered via DI in _config/fluent.yml to replace the standard policy.
 */
class GridAwareDeleteLocalisationPolicy
{
    use Injectable;

    /**
     * @param DataObject $record The record whose locale is being cleared
     * @return bool Whether other localisations remain (blocking upstream deletion)
     */
    public function delete(DataObject $record): bool
    {
        // Delegate to the real Fluent policy for standard localised field cleanup
        $parent = new DeleteLocalisationPolicy();
        $result = $parent->delete($record);

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
