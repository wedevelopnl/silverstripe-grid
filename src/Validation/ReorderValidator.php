<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Validation;

use NoDiscard;
use Override;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;

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

        $boundary = $this->checkSharedBoundary($element, $targetParent);
        if ($boundary->isErr()) {
            return $boundary;
        }

        // Placement time only. A block with no content resolves no placement
        // class, so there is no position it could legally take — but an
        // ALREADY-placed reference whose block was later emptied stays valid,
        // which is why this lives here and not in the shared rules. Only a
        // placement can answer null; every ordinary element answers its own
        // class, so no instanceof guard is needed.
        if ($element->getPlacementClass() === null) {
            return Result::fail(new ValidationError(
                message: 'This shared block has no content yet, so it cannot be placed.',
                field: 'placement',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.BLOCK_EMPTY',
            ));
        }

        return $this->checkPlacementRules($element, $targetParent);
    }

    /**
     * Content may not be dragged across a shared-block boundary in either
     * direction: a page's content would silently become shared, and a block's
     * content would silently vanish from every other page using it. The client
     * filters these drops out of collision detection; this is the backstop.
     *
     * @return Result<GridElement>
     */
    private function checkSharedBoundary(GridElement $element, DataObject $targetParent): Result
    {
        $currentParent = $element->Parent();

        // A fresh element has no source context yet — where it may land is
        // decided by the placement rules and by the endpoint that created it.
        if ($currentParent === null || !$currentParent->exists()) {
            return Result::ok($element);
        }

        $sourceBlock = $this->sharedContextOf($element);
        $targetBlock = $this->sharedContextOf($targetParent);

        // 0 stands for "page-local", so local -> local compares equal too.
        $sourceId = $sourceBlock instanceof SharedBlock ? (int) $sourceBlock->ID : 0;
        $targetId = $targetBlock instanceof SharedBlock ? (int) $targetBlock->ID : 0;

        if ($sourceId === $targetId) {
            return Result::ok($element);
        }

        return Result::fail(new ValidationError(
            message: 'Content cannot be moved into or out of a shared block.',
            field: 'placement',
            code: ValidationErrorCode::HierarchyViolation,
            key: self::class . '.SHARED_BOUNDARY',
            params: ['element' => $element->singular_name()],
        ));
    }
}
