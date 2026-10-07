<?php

declare(strict_types=1);

namespace WeDevelop\Grid\UserForms;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Service\SharedBlockUsageResolver;

/**
 * Finds the form element a page's grid-form route may serve: one in the page's
 * own tree, or inside a shared block the page places — on the ambient stage
 * and locale. Structural only: the page controller has already enforced the
 * host page's canView().
 */
class UserFormElementResolver
{
    use Injectable;

    public function __construct(
        private readonly SharedBlockUsageResolver $usageResolver,
    ) {
    }

    /** @param positive-int $id */
    public function resolve(SiteTree $page, int $id): ?UserFormElement
    {
        $element = UserFormElement::get()->byID($id);

        if (!$element instanceof UserFormElement) {
            return null;
        }

        $owner = $element->getPage();

        if ($owner instanceof SiteTree) {
            return (int) $owner->ID === (int) $page->ID ? $element : null;
        }

        if ($owner instanceof SharedBlock) {
            foreach ($this->usageResolver->pagesUsing($owner) as $using) {
                if ($using instanceof SiteTree && (int) $using->ID === (int) $page->ID) {
                    return $element;
                }
            }
        }

        return null;
    }
}
