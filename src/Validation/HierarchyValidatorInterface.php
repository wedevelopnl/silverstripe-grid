<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use NoDiscard;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;

interface HierarchyValidatorInterface
{
    /** @return Result<GridElement> */
    #[NoDiscard('The Result reports whether the element placement is valid; discarding it silently skips the hierarchy check.')]
    public function validate(GridElement $element): Result;
}
