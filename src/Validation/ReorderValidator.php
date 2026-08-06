<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use NoDiscard;
use Override;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;

class ReorderValidator implements ReorderValidatorInterface
{
    use PlacementRulesTrait;

    /** @return Result<GridElement> */
    #[NoDiscard('The Result reports whether the move is valid; discarding it silently accepts an invalid reorder.')]
    #[Override]
    public function validate(GridElement $element, DataObject $targetParent): Result
    {
        if (
            (int) $element->ParentID === (int) $targetParent->ID
            && $element->ParentClass === $targetParent::class
        ) {
            return Result::ok($element);
        }

        return $this->checkPlacementRules($element, $targetParent);
    }
}
