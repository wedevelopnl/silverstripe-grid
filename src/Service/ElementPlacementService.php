<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\WriteResult;

/**
 * Single write-side authority for element placement.
 *
 * Handles both moves of existing elements ({@see reorder()}) and placement
 * of newly-written elements ({@see insertAfter()}). Every mutation runs
 * through {@see ReorderValidatorInterface::validate()} and the shared
 * reindex pipeline.
 */
class ElementPlacementService
{
    public function __construct(
        private readonly ReorderValidatorInterface $validator,
        private readonly GridElementRepositoryInterface $elementRepository,
    ) {
    }

    /**
     * Place a just-written element after a reference sibling in its parent
     * (or at the start of the parent when $afterElementId is null).
     *
     * Semantically equivalent to {@see reorder()} — both splice the element
     * into its parent's sibling list and reindex Sort. The two methods exist
     * so call sites can express intent: {@see reorder()} means "this element
     * already lives somewhere and should move," while {@see insertAfter()}
     * means "this element was just written and needs its initial position."
     * Both go through the same validator and DB path.
     *
     * Preconditions: $element has been written (has an ID) and its
     * ParentID/ParentClass already match $parent.
     *
     * @param positive-int|null $afterElementId
     * @return Result<GridElement>
     */
    #[NoDiscard('The Result reports placement validation failures; discarding it silently accepts an invalid placement.')]
    public function insertAfter(GridElement $element, DataObject $parent, ?int $afterElementId): Result
    {
        return $this->reorder($element, $parent, $afterElementId);
    }

    /**
     * @param positive-int|null $afterElementId
     * @return Result<GridElement>
     */
    #[NoDiscard('The Result reports reorder validation failures; discarding it silently accepts an invalid move.')]
    public function reorder(GridElement $element, DataObject $targetParent, ?int $afterElementId): Result
    {
        $validationResult = $this->validator->validate($element, $targetParent);
        if ($validationResult->isErr()) {
            return $validationResult;
        }

        /** @var positive-int $targetParentId */
        $targetParentId = $targetParent->ID;

        /** @var positive-int $sourceParentId */
        $sourceParentId = $element->ParentID;
        $isCrossParent = $sourceParentId !== $targetParentId;

        // Fetch target siblings, excluding the moved element.
        $targetSiblings = $this->excludeElement(
            $this->siblingSequence($targetParent::class, $targetParentId, $element),
            $element,
        );

        $insertionIndex = $this->resolveInsertionIndex($targetSiblings, $afterElementId);
        if ($insertionIndex === null) {
            return Result::fail(new ValidationError(
                message: 'The reference element no longer exists in the target parent.',
                field: 'afterElementID',
                key: self::class . '.AFTER_ELEMENT_NOT_FOUND',
            ));
        }

        array_splice($targetSiblings, $insertionIndex, 0, [$element]);

        if (!$isCrossParent) {
            $dirtyElements = $this->reindex($targetSiblings);

            return $this->persistAndReturn($dirtyElements, $element);
        }

        // Cross-parent: reassign ParentID and ParentClass, reindex target, then reindex source to close gaps
        /** @var class-string $sourceParentClass */
        $sourceParentClass = $element->ParentClass;
        $element->ParentID = $targetParentId;
        $element->ParentClass = $targetParent::class;

        $dirty = $this->reindex($targetSiblings, $element);

        $sourceSiblings = $this->excludeElement(
            $this->siblingSequence($sourceParentClass, $sourceParentId, $element),
            $element,
        );

        $dirtyElements = [...$dirty, ...$this->reindex($sourceSiblings)];

        return $this->persistAndReturn($dirtyElements, $element);
    }

    /**
     * Wrapped in a DB transaction so a mid-loop write failure cannot leave
     * siblings half-reindexed. Only a ValidationException is translated into a
     * failure Result by WriteResult::from; any other exception propagates
     * uncaught and surfaces as a 500 (after the rollback).
     *
     * @param list<GridElement> $dirtyElements
     * @return Result<GridElement>
     */
    private function persistAndReturn(array $dirtyElements, GridElement $element): Result
    {
        /** @var Result<GridElement> */
        return Transactional::run(static fn (): Result => WriteResult::from(
            static function () use ($dirtyElements, $element): GridElement {
                foreach ($dirtyElements as $dirtyElement) {
                    $dirtyElement->write();
                }

                return $element;
            },
        ));
    }

    /**
     * @param list<GridElement> $siblings
     * @param positive-int|null $afterElementId
     * @return int<0, max>|null Index to splice at, or null if afterElementId not found
     */
    private function resolveInsertionIndex(array $siblings, ?int $afterElementId): ?int
    {
        if ($afterElementId === null) {
            return 0;
        }

        foreach ($siblings as $index => $sibling) {
            if ($sibling->ID === $afterElementId) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The Sort sequence $element competes in under the given parent, in order.
     *
     * At page root that sequence is scoped to a zone and spans every root class
     * ({@see GridElement::ROOT_ELEMENT_CLASSES}), so only the zone-aware
     * repository query returns it — filtering the base list by class here is
     * what dropped shared-block placements out of the list and made any move
     * anchored on one fail. Inside a container there is no zone and a single
     * parent-wide sequence covers every child class.
     *
     * @param class-string $parentClass
     * @param positive-int $parentId
     * @return list<GridElement>
     */
    private function siblingSequence(string $parentClass, int $parentId, GridElement $element): array
    {
        if (is_a($parentClass, GridElement::class, true)) {
            return $this->elementRepository->findByParentIds([$parentId], $parentClass);
        }

        return $this->elementRepository->findByParents(
            [$parentClass => [$parentId]],
            (string) $element->Zone,
        );
    }

    /**
     * @param list<GridElement> $siblings
     * @return list<GridElement>
     */
    private function excludeElement(array $siblings, GridElement $element): array
    {
        return array_values(array_filter(
            $siblings,
            static fn (GridElement $sibling): bool => $sibling->ID !== $element->ID,
        ));
    }

    /**
     * Assign 1-based Sort values and return only elements that changed.
     *
     * For cross-parent moves, the moved element's ParentID has changed even if its
     * Sort stays the same — pass it as $alwaysDirty to force inclusion.
     *
     * @param list<GridElement> $siblings
     * @return list<GridElement>
     */
    private function reindex(array $siblings, ?GridElement $alwaysDirty = null): array
    {
        $dirty = [];

        foreach ($siblings as $index => $sibling) {
            $newSort = $index + 1;

            if ($sibling->Sort !== $newSort) {
                $sibling->Sort = $newSort;
                $dirty[] = $sibling;
            } elseif ($alwaysDirty instanceof GridElement && $sibling->ID === $alwaysDirty->ID) {
                $dirty[] = $sibling;
            }
        }

        return $dirty;
    }
}
