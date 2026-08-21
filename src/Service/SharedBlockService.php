<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Value\WriteResult;

/**
 * The four operations that move content across the shared boundary: placing a
 * block on a page, promoting page content into the library, pulling a placement
 * back out as a local copy, and publishing the block itself.
 *
 * All four write to DRAFT only; live changes on the next publish of the page or
 * the block. That leaves one window worth knowing about: converting disowns the
 * subtree from the page, so the NEXT page publish clears its live parent link
 * and the placement renders nothing on live until the block is published too.
 * The editor's notPublished badge is what warns the author.
 */
class SharedBlockService
{
    public function __construct(
        private readonly ReorderValidatorInterface $validator,
        private readonly ElementPlacementService $placementService,
    ) {
    }

    /**
     * Create a reference to $block under $parent.
     *
     * @param positive-int|null $insertAfterElementID
     * @return Result<SharedBlockReference>
     */
    #[NoDiscard('The Result carries the created reference or the validation errors that blocked it.')]
    public function place(
        SharedBlock $block,
        DataObject $parent,
        string $zone,
        ?int $insertAfterElementID,
    ): Result {
        $reference = SharedBlockReference::create();
        $reference->BlockID = $block->ID;

        // Zone is a page-root concept; inside a container the reference is just
        // another child and its Zone column stays empty.
        if ($parent instanceof SiteTree) {
            $reference->Zone = $zone;
        }

        // Validate before writing so an incompatible root type (a row-rooted
        // block dropped at page level) never leaves a row behind — and while
        // the reference is still UNPARENTED, so the validator sees a genuine
        // move into $parent. Assigning the parent first makes its same-parent
        // guard short-circuit to ok, turning this check into a no-op.
        $validation = $this->validator->validate($reference, $parent);
        if ($validation->isErr()) {
            return Result::fail(...$validation->errors());
        }

        $reference->ParentID = $parent->ID;
        $reference->ParentClass = $parent::class;

        return Transactional::run(
            function () use ($reference, $parent, $insertAfterElementID): Result {
                $write = WriteResult::from(static function () use ($reference): SharedBlockReference {
                    $reference->write();

                    return $reference;
                });

                if ($write->isErr()) {
                    return $write;
                }

                if ($insertAfterElementID === null) {
                    return $write;
                }

                $placed = $this->placementService->insertAfter($reference, $parent, $insertAfterElementID);

                return $placed->isErr() ? Result::fail(...$placed->errors()) : Result::ok($reference);
            },
        );
    }

    /**
     * Move $root's subtree into a new SharedBlock and leave a reference in its
     * place.
     *
     * No content is copied: the root is re-parented with a single UPDATE and its
     * descendants follow automatically, since they point at their unchanged
     * parent. Element IDs and version history survive intact.
     *
     * @return Result<SharedBlock>
     */
    #[NoDiscard('The Result carries the new block or the reason the conversion was refused.')]
    public function convertToShared(GridElement $root, string $title): Result
    {
        if ($root instanceof SharedBlockReference) {
            return Result::fail(new ValidationError(
                message: 'This element is already a shared block.',
                field: 'element',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.ALREADY_SHARED',
            ));
        }

        if ($root->getPage() instanceof SharedBlock) {
            return Result::fail(new ValidationError(
                message: 'Content inside a shared block cannot be shared again.',
                field: 'element',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.SHARED_NESTING',
            ));
        }

        $parent = $root->Parent();
        if ($parent === null || !$parent->exists()) {
            return Result::fail(new ValidationError(
                message: 'Only an element that sits on a page can be shared.',
                field: 'element',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.NOT_PLACED',
            ));
        }

        return Transactional::run(fn(): Result => WriteResult::from(function () use ($root, $title): SharedBlock {
            $block = SharedBlock::create();
            $block->Title = $title;
            $block->write();

            // Capture the vacated position before the root moves out of it.
            $sort = (int) $root->Sort;
            $zone = $root instanceof Section ? (string) $root->Zone : '';
            $parentId = (int) $root->ParentID;
            $parentClass = (string) $root->ParentClass;

            $root->ParentID = $block->ID;
            $root->ParentClass = SharedBlock::class;

            // Zone is placement data and moves to the reference with it.
            if ($root instanceof Section) {
                $root->Zone = '';
            }

            $root->write();

            $reference = SharedBlockReference::create();
            $reference->BlockID = $block->ID;
            $reference->ParentID = $parentId;
            $reference->ParentClass = $parentClass;
            $reference->Zone = $zone;
            // Written directly, not placed: the Sort is exact — the reference
            // takes over the position the subtree just vacated.
            $reference->Sort = $sort;
            $reference->write();

            return $block;
        }));
    }

    /**
     * Replace $reference with an independent deep copy of the block's subtree.
     *
     * @return Result<GridElement>
     */
    #[NoDiscard('The Result carries the detached copy or the reason the detach was refused.')]
    public function detach(SharedBlockReference $reference): Result
    {
        $block = $reference->Block();
        $root = $block?->getRootElement();

        if ($root === null) {
            return Result::fail(new ValidationError(
                message: 'This shared block has no content to detach.',
                field: 'element',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.BLOCK_EMPTY',
            ));
        }

        return Transactional::run(fn(): Result => WriteResult::from(static function () use ($reference, $root): GridElement {
            // cascade_duplicates carries the whole subtree; the copy comes
            // back still pointing at the block, so re-point it immediately.
            $copy = $root->duplicate(true);

            $copy->ParentID = $reference->ParentID;
            $copy->ParentClass = $reference->ParentClass;
            $copy->Sort = $reference->Sort;

            if ($copy instanceof Section) {
                $copy->Zone = (string) $reference->Zone;
            }

            $copy->write();

            $reference->doArchive();

            return $copy;
        }));
    }

    /**
     * Publish or unpublish the block. The $owns chain carries the subtree, so
     * one call moves the whole block on every page that places it.
     *
     * @return Result<SharedBlock>
     */
    #[NoDiscard('The Result reports whether the publish succeeded.')]
    public function setPublished(SharedBlock $block, bool $published): Result
    {
        return WriteResult::from(static function () use ($block, $published): SharedBlock {
            if ($published) {
                $block->publishRecursive();
            } else {
                $block->doUnpublish();
            }

            return $block;
        });
    }
}
