<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Contract;

use NoDiscard;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;

interface ReorderValidatorInterface
{
    /** @return Result<GridElement> */
    #[NoDiscard('The Result reports whether the move is valid; discarding it silently accepts an invalid reorder.')]
    public function validate(GridElement $element, DataObject $targetParent): Result;
}
