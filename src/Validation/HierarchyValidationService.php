<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

class HierarchyValidationService implements HierarchyValidatorInterface
{
    use ElementAllowanceTrait;

    /** @return Result<GridElement> */
    #[Override]
    public function validate(GridElement $element): Result
    {
        $parent = $element->Parent();
        if ($parent === null || !$parent->exists()) {
            return Result::ok($element);
        }

        // Page-level: parent is a SiteTree — check can_be_root
        if ($parent instanceof SiteTree) {
            if ($element->config()->get('can_be_root') === false) {
                return Result::fail(new ValidationError(
                    message: sprintf(
                        '%s cannot be placed at page level.',
                        $element->singular_name(),
                    ),
                    field: 'placement',
                ));
            }

            return Result::ok($element);
        }

        // Container-level: check allowed_elements / disallowed_elements on parent
        if ($this->isElementAllowed($element::class, $parent)) {
            return Result::ok($element);
        }

        return Result::fail(new ValidationError(
            message: sprintf(
                '%s cannot be placed inside %s.',
                $element->singular_name(),
                $parent->singular_name(),
            ),
            field: 'placement',
        ));
    }

}
