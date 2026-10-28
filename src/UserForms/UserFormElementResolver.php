<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;

/**
 * Finds the form element a page's grid-form route may serve: one in the page's
 * own tree, or inside a shared block the page places — on the ambient stage
 * and locale. Structural only: the page controller has already enforced the
 * host page's canView().
 */
class UserFormElementResolver
{
    use Injectable;

    /** @param positive-int $id */
    public function resolve(SiteTree $page, int $id): ?UserFormElement
    {
        $element = UserFormElement::get()->byID($id);

        if (!$element instanceof UserFormElement) {
            return null;
        }

        $owner = $element->getPage();

        if ($owner instanceof SiteTree) {
            return $owner->ID === $page->ID ? $element : null;
        }

        if ($owner instanceof SharedBlock) {
            // Not SharedBlockUsageResolver: it counts every locale's placements,
            // and a page shows a block only where it is placed in this one.
            foreach (SharedBlockReference::get()->filter(['BlockID' => $owner->ID]) as $reference) {
                $using = $reference->getPage();

                if ($using instanceof SiteTree && $using->ID === $page->ID) {
                    return $element;
                }
            }
        }

        return null;
    }
}
