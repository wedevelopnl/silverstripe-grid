<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Value\ContainerType;
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
        // No nesting: a reference may never sit inside a shared subtree. This is
        // what keeps cycle detection out of the module entirely. Checked first
        // because it holds regardless of what the block contains.
        if ($element instanceof SharedBlockReference && $this->sharedContextOf($parent) !== null) {
            return Result::fail(new ValidationError(
                message: 'A shared block cannot be placed inside another shared block.',
                field: 'placement',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.SHARED_NESTING',
                params: ['element' => $element->singular_name()],
            ));
        }

        // A reference stands in for its block's root, so every rule below judges
        // it by that class rather than by SharedBlockReference itself.
        $effectiveClass = $element->getPlacementClass();

        // An empty block yields no class to judge by, so the rules below cannot
        // be evaluated. That is NOT a persistence failure: a block may be
        // emptied — or, under Fluent, hold no content in this locale — long
        // after it was placed, and the pages carrying it must stay writable and
        // publishable. Refusing to PLACE an empty block is a separate,
        // placement-time rule ({@see ReorderValidator::validate()}); enforcing
        // it here instead made page publish throw on a state the editor and
        // the renderer both handle.
        if ($effectiveClass === null) {
            return Result::ok($element);
        }

        // Page-level: parent is a SiteTree — check canBeRoot via ContainerType
        if ($parent instanceof SiteTree) {
            $containerType = $this->containerTypeOfClass($effectiveClass);

            if ($containerType === null || !$containerType->canBeRoot()) {
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

        // Library root: a block holds one subtree of any shape, so any element
        // class may root it. References are already excluded by the nesting rule.
        if ($parent instanceof SharedBlock) {
            return Result::ok($element);
        }

        // Container-level: delegate to the parent's ContainerType
        if ($parent instanceof ContainerInterface && $parent->getContainerType()->isChildAllowed($effectiveClass)) {
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


    /**
     * The ContainerType a class represents, or null for a leaf element.
     *
     * The class-based counterpart of {@see ContainerInterface::getContainerType()}:
     * a reference has no instance of its effective class to ask.
     *
     * @param class-string<GridElement> $class
     */
    private function containerTypeOfClass(string $class): ?ContainerType
    {
        return match (true) {
            is_a($class, Section::class, true) => ContainerType::Section,
            is_a($class, Row::class, true) => ContainerType::Row,
            is_a($class, Column::class, true) => ContainerType::Column,
            default => null,
        };
    }

    /** The SharedBlock a node lives under, or null when it sits in page-local content. */
    private function sharedContextOf(DataObject $node): ?SharedBlock
    {
        if ($node instanceof SharedBlock) {
            return $node;
        }

        if (!$node instanceof GridElement) {
            return null;
        }

        $owner = $node->getPage();

        return $owner instanceof SharedBlock ? $owner : null;
    }
}
