<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Extension\FluentIsolatedExtension;
use TractorCow\Fluent\Model\Delete\DeleteLocalisationPolicy;
use WeDevelop\Grid\Extensions\GridPageExtension;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;

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
        // A shared block is a single cross-locale record with no FluentExtension,
        // so the stock policy would reject it outright. Its SUBTREE is
        // locale-isolated all the same and must go with the locale; the record
        // itself survives, which is what the `true` reports upstream.
        if ($record instanceof SharedBlock) {
            if (GridElement::has_extension(FluentIsolatedExtension::class)) {
                $this->deleteRootElements($record->ID, SharedBlock::class);
            }

            return true;
        }

        // Delegate to the real Fluent policy for standard localised field cleanup
        $parent = new DeleteLocalisationPolicy();
        $result = $parent->delete($record);

        if (!GridElement::has_extension(FluentIsolatedExtension::class)) {
            return $result;
        }

        if (!$record->hasExtension(GridPageExtension::class)) {
            return $result;
        }

        // FluentIsolatedExtension::augmentSQL filters by current locale, so
        // this only ever reaches the current locale's rows. cascade_deletes on
        // each root handles Rows → Columns → ContentElements.
        //
        // Both root classes, not just Sections: a shared block PLACEMENT is a
        // root element too, and it is not a Section, so the Sections() loop
        // alone would strand it in a locale that no longer exists.
        $this->deleteRootElements($record->ID, $record::class);

        return $result;
    }

    /**
     * Delete every grid element parented directly to this record in the active
     * locale. Placements are deleted, never their blocks — removing a locale
     * from one page must not touch the shared source.
     *
     * @param class-string $parentClass
     */
    private function deleteRootElements(mixed $parentId, string $parentClass): void
    {
        $roots = GridElement::get()->filter([
            'ParentID' => $parentId,
            'ParentClass' => $parentClass,
        ]);

        foreach ($roots as $element) {
            $element->delete();
        }
    }
}
