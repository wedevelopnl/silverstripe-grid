<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

class HierarchyValidationService implements HierarchyValidatorInterface
{
    /** @return Result<GridElement> */
    #[Override]
    public function validate(GridElement $element): Result
    {
        $parent = $element->Parent();
        if ($parent === null || !$parent->exists()) {
            return Result::ok($element);
        }

        // Page-level: parent is a SiteTree — check canBeRoot via ContainerType
        if ($parent instanceof SiteTree) {
            if (!$this->canPlaceAtPageLevel($element)) {
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

        // Container-level: delegate to parent's ContainerType
        if ($parent instanceof ContainerInterface && $parent->getContainerType()->isChildAllowed($element::class)) {
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

    private function canPlaceAtPageLevel(GridElement $element): bool
    {
        return $element instanceof ContainerInterface
            && $element->getContainerType()->canBeRoot();
    }
}
