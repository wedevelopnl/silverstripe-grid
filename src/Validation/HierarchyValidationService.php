<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use NoDiscard;
use Override;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;

class HierarchyValidationService implements HierarchyValidatorInterface
{
    use PlacementRulesTrait;

    /** @return Result<GridElement> */
    #[NoDiscard('The Result reports whether the element placement is valid; discarding it silently skips the hierarchy check.')]
    #[Override]
    public function validate(GridElement $element): Result
    {
        $parent = $element->Parent();
        if ($parent === null || !$parent->exists()) {
            return Result::ok($element);
        }

        return $this->checkPlacementRules($element, $parent);
    }
}
