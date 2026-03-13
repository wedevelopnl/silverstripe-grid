<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use Override;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;

class ReorderValidator implements ReorderValidatorInterface
{
    /** @return Result<GridElement> */
    #[Override]
    public function validate(GridElement $element, DataObject $targetParent): Result
    {
        if ((int) $element->ParentID === (int) $targetParent->ID) {
            return Result::ok($element);
        }

        return $this->checkHierarchyRules($element, $targetParent);
    }

    /** @return Result<GridElement> */
    private function checkHierarchyRules(GridElement $element, DataObject $targetParent): Result
    {
        // Page-level: target parent is a SiteTree — check canBeRoot via ContainerType
        if ($targetParent instanceof SiteTree) {
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

        // Container-level: delegate to target parent's ContainerType
        if ($targetParent instanceof ContainerInterface && $targetParent->getContainerType()->isChildAllowed($element::class)) {
            return Result::ok($element);
        }

        return Result::fail(new ValidationError(
            message: sprintf(
                '%s cannot be placed inside %s.',
                $element->singular_name(),
                $targetParent->singular_name(),
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
