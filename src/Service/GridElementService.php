<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\ContentElement;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Value\WriteResult;

/**
 * Domain service for grid element lifecycle operations: creation and duplication.
 *
 * Follows the same pattern as {@see ElementPlacementService}: receives already-loaded,
 * already-authorized objects and returns {@see Result} for domain validation failures.
 * Delegates all Sort/ParentID mutations to {@see ElementPlacementService} so every
 * placement path goes through the shared validator.
 */
final class GridElementService
{
    public function __construct(
        private readonly ReorderValidatorInterface $validator,
        private readonly ElementPlacementService $placementService,
    ) {
    }

    /**
     * Create a container element under the given parent.
     *
     * @param non-empty-string $zone
     * @param positive-int|null $insertAfterElementID
     * @return Result<GridElement>
     */
    public function createElement(
        DataObject $parent,
        ContainerType $containerType,
        string $zone,
        ?int $insertAfterElementID,
    ): Result {
        /** @var GridElement $newElement */
        $newElement = Injector::inst()->create($containerType->toElementClass());
        $newElement->ParentID = $parent->ID;
        $newElement->ParentClass = $parent::class;

        if ($containerType === ContainerType::Section) {
            $newElement->Zone = $zone;
        }

        $writeResult = WriteResult::from(static function () use ($newElement): GridElement {
            $newElement->write();
            return $newElement;
        });

        if ($writeResult->isErr() || $insertAfterElementID === null) {
            return $writeResult;
        }

        return $this->placementService->insertAfter($newElement, $parent, $insertAfterElementID);
    }

    /**
     * Create a content element under the given column.
     *
     * @param class-string<ContentElement> $className
     * @param positive-int|null $insertAfterElementID
     * @return Result<GridElement>
     */
    public function createContentElement(
        Column $parent,
        string $className,
        ?int $insertAfterElementID,
    ): Result {
        /** @var GridElement $newElement ContentElement extends GridElement */
        $newElement = Injector::inst()->create($className);
        $newElement->ParentID = $parent->ID;
        $newElement->ParentClass = $parent::class;

        $writeResult = WriteResult::from(static function () use ($newElement): GridElement {
            $newElement->write();
            return $newElement;
        });

        if ($writeResult->isErr() || $insertAfterElementID === null) {
            return $writeResult;
        }

        return $this->placementService->insertAfter($newElement, $parent, $insertAfterElementID);
    }

    /**
     * Shallow-duplicate an element and insert after the original.
     *
     * @return Result<GridElement>
     */
    public function duplicateElement(GridElement $element): Result
    {
        $clone = $element->duplicate(false);

        /** @var non-empty-string $cloneTitle Elements always have a title after write */
        $cloneTitle = $clone->Title ?: $element->Title ?: 'Untitled';
        $clone->Title = TitleGenerator::generateCopyTitle($cloneTitle);
        $clone->Sort = 0;
        $clone->ParentID = $element->ParentID;
        $clone->ParentClass = $element->ParentClass;

        /** @var positive-int $elementId */
        $elementId = (int) $element->ID;

        $writeResult = WriteResult::from(static function () use ($clone): GridElement {
            $clone->write();
            return $clone;
        });

        if ($writeResult->isErr()) {
            return $writeResult;
        }

        $parent = $element->Parent();
        assert($parent instanceof DataObject);

        return $this->placementService->insertAfter($clone, $parent, $elementId);
    }

    /**
     * Deep-duplicate an element to a different parent/page/zone.
     *
     * Performs ownership validation (C1: target parent belongs to claimed page/zone)
     * and hierarchy validation (C2: element type is allowed under target parent)
     * before duplicating.
     *
     * @param positive-int $targetPageId
     * @param non-empty-string $targetZone
     * @return Result<GridElement>
     */
    public function duplicateElementTo(
        GridElement $element,
        DataObject $targetParent,
        int $targetPageId,
        string $targetZone,
    ): Result {
        // C1: Validate target parent belongs to the claimed page/zone
        $ownershipResult = $this->validateOwnership(
            $element,
            $targetParent,
            $targetPageId,
            $targetZone,
        );
        if ($ownershipResult->isErr()) {
            return Result::fail(...$ownershipResult->errors());
        }

        // C2: Validate hierarchy rules before deep copy
        $hierarchyResult = $this->validator->validate($element, $targetParent);
        if ($hierarchyResult->isErr()) {
            return Result::fail(...$hierarchyResult->errors());
        }

        // Deep-duplicate the entire subtree (follows cascade_duplicates)
        $clone = $element->duplicate(true);

        // Re-parent to target
        /** @var positive-int $targetParentId */
        $targetParentId = $targetParent->ID;
        $clone->ParentID = $targetParentId;
        $clone->ParentClass = $targetParent::class;

        // Set zone for sections
        if ($clone instanceof Section) {
            $clone->Zone = $targetZone;
        }

        // Generate copy title (top-level only — children keep originals)
        /** @var non-empty-string $cloneTitle */
        $cloneTitle = $clone->Title ?: $element->Title ?: 'Untitled';
        $clone->Title = TitleGenerator::generateCopyTitle($cloneTitle);

        $clone->Sort = 0;

        return WriteResult::from(static function () use ($clone): GridElement {
            $clone->write();
            return $clone;
        });
    }

    /**
     * C1: Validate that the target parent belongs to the claimed page and zone.
     *
     * For sections, the target parent IS the page, so parentId must equal pageId.
     * For non-sections, walks the ancestor chain to verify page ownership and
     * finds the root section to verify zone membership.
     *
     * @param positive-int $targetPageId
     * @param non-empty-string $targetZone
     * @return Result<null>
     */
    private function validateOwnership(
        GridElement $element,
        DataObject $targetParent,
        int $targetPageId,
        string $targetZone,
    ): Result {
        $isSection = $element instanceof Section;

        if ($isSection) {
            /** @var positive-int $targetParentId */
            $targetParentId = $targetParent->ID;
            if ($targetParentId !== $targetPageId) {
                return Result::fail(new ValidationError(
                    message: 'Target parent does not match the claimed page.',
                    field: 'ownership',
                    code: ValidationErrorCode::OwnershipDenied,
                    key: self::class . '.OWNERSHIP_PAGE_MISMATCH',
                ));
            }

            return Result::ok(null);
        }

        // Non-section: walk up to the page and verify ownership
        assert($targetParent instanceof GridElement);
        $owningPage = $targetParent->getPage();

        if (!$owningPage instanceof SiteTree || (int) $owningPage->ID !== $targetPageId) {
            return Result::fail(new ValidationError(
                message: 'Target parent does not belong to the claimed page.',
                field: 'ownership',
                code: ValidationErrorCode::OwnershipDenied,
                key: self::class . '.OWNERSHIP_PAGE_MISMATCH_NON_SECTION',
            ));
        }

        // Find the root section and verify its zone matches
        $ancestor = $targetParent;
        while ($ancestor instanceof GridElement && !$ancestor instanceof Section) {
            $parent = $ancestor->Parent();
            $ancestor = $parent instanceof GridElement ? $parent : null;
        }

        if ($ancestor instanceof Section && $ancestor->Zone !== $targetZone) {
            return Result::fail(new ValidationError(
                message: 'Target parent does not belong to the claimed zone.',
                field: 'ownership',
                code: ValidationErrorCode::OwnershipDenied,
                key: self::class . '.OWNERSHIP_ZONE_MISMATCH',
            ));
        }

        return Result::ok(null);
    }
}
