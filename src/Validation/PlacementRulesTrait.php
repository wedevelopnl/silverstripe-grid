<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;

/**
 * The single evaluation of the hardcoded {@see \WeDevelop\Grid\Value\ContainerType}
 * placement rules, shared by write-time ({@see HierarchyValidationService}) and
 * reorder-time ({@see ReorderValidator}) validation.
 *
 * Deliberately a trait rather than a collaborator: `self::class` resolves to the
 * using class, so each validator keeps raising errors under its own translation
 * keys, and neither constructor gains a dependency.
 */
trait PlacementRulesTrait
{
    /**
     * Whether $element may live directly under $parent.
     *
     * @return Result<GridElement>
     */
    protected function checkPlacementRules(GridElement $element, DataObject $parent): Result
    {
        // Page-level: parent is a SiteTree — check canBeRoot via ContainerType
        if ($parent instanceof SiteTree) {
            if (!$element instanceof ContainerInterface || !$element->getContainerType()->canBeRoot()) {
                return Result::fail(new ValidationError(
                    message: \sprintf(
                        '%s cannot be placed at page level.',
                        $element->singular_name(),
                    ),
                    field: 'placement',
                    code: ValidationErrorCode::HierarchyViolation,
                    key: self::class . '.PAGE_LEVEL_REJECTED',
                    params: ['element' => $element->singular_name()],
                ));
            }

            return Result::ok($element);
        }

        // Container-level: delegate to the parent's ContainerType
        if ($parent instanceof ContainerInterface && $parent->getContainerType()->isChildAllowed($element::class)) {
            return Result::ok($element);
        }

        return Result::fail(new ValidationError(
            message: \sprintf(
                '%s cannot be placed inside %s.',
                $element->singular_name(),
                $parent->singular_name(),
            ),
            field: 'placement',
            code: ValidationErrorCode::HierarchyViolation,
            key: self::class . '.PARENT_REJECTED',
            params: ['element' => $element->singular_name(), 'parent' => $parent->singular_name()],
        ));
    }
}
