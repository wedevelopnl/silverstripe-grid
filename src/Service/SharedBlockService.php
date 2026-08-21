<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Section;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Value\Result;
use WeDevelop\Grid\Value\SharedBlockDeleteMode;
use WeDevelop\Grid\Value\ValidationError;
use WeDevelop\Grid\Value\ValidationErrorCode;
use WeDevelop\Grid\Value\WriteResult;

/**
 * The operations that move content across the shared boundary: placing a block
 * on a page, promoting page content into the library, pulling a placement back
 * out as a local copy, publishing the block itself, and retiring it.
 *
 * Placing, converting and detaching write to DRAFT only; live changes on the
 * next publish of the page or the block. That leaves one window worth knowing
 * about: converting disowns the subtree from the page, so the NEXT page publish
 * clears that subtree's live parent link and the placement renders nothing on
 * live until the block is published too. The editor's notPublished badge is
 * what warns the author.
 *
 * Publishing and deleting are the exceptions — both reach live immediately, on
 * every consuming page at once, which is the point of them.
 */
class SharedBlockService
{
    public function __construct(
        private readonly ReorderValidatorInterface $validator,
        private readonly ElementPlacementService $placementService,
        private readonly GridElementRepositoryInterface $elementRepository,
    ) {
    }

    /**
     * Create a library block seeded with its single root element.
     *
     * Block and root are written together because a block without a root is a
     * broken state, not an intermediate one: it resolves no effective root
     * class, so every page placing it renders nothing and later grid writes on
     * those pages fail with BLOCK_EMPTY.
     *
     * The root's own write cascades the rest — a Section scaffolds a Row and a
     * Column, a Row scaffolds a Column, a Column and a leaf scaffold nothing.
     * No Zone is assigned: zone is placement data that belongs to a page root,
     * and the library's subtree has none ({@see convertToShared()} clears it
     * for the same reason).
     *
     * @param class-string<GridElement> $rootClass
     * @return Result<SharedBlock>
     */
    #[NoDiscard('The Result carries the created block or the reason the write failed.')]
    public function create(string $rootClass): Result
    {
        return Transactional::run(fn(): Result => WriteResult::from(
            static function () use ($rootClass): SharedBlock {
                $block = SharedBlock::create();
                $block->write();

                $root = Injector::inst()->create($rootClass);
                $root->ParentID = $block->ID;
                $root->ParentClass = SharedBlock::class;
                $root->write();

                return $block;
            },
        ));
    }

    /**
     * Create a reference to $block under $parent.
     *
     * @param positive-int|null $insertAfterElementID
     * @param bool $insertAtStart Place the reference before all existing siblings. Mutually exclusive with $insertAfterElementID.
     * @return Result<SharedBlockReference>
     */
    #[NoDiscard('The Result carries the created reference or the validation errors that blocked it.')]
    public function place(
        SharedBlock $block,
        DataObject $parent,
        string $zone,
        ?int $insertAfterElementID,
        bool $insertAtStart = false,
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
            function () use ($reference, $parent, $insertAfterElementID, $insertAtStart): Result {
                $write = WriteResult::from(static function () use ($reference): SharedBlockReference {
                    $reference->write();

                    return $reference;
                });

                if ($write->isErr()) {
                    return $write;
                }

                // A null reference id tells the placement service "splice at
                // index 0 and reindex the rest" — the same contract
                // GridElementService::writeThenPlace() relies on.
                if ($insertAtStart) {
                    $placed = $this->placementService->insertAfter($reference, $parent, null);

                    return $placed->isErr() ? Result::fail(...$placed->errors()) : Result::ok($reference);
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

        if ($this->containsPlacement($root)) {
            return Result::fail(new ValidationError(
                message: 'This content holds a shared block, which cannot be nested inside another one.',
                field: 'element',
                code: ValidationErrorCode::HierarchyViolation,
                key: self::class . '.NESTED_PLACEMENT',
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
     * Whether any descendant of $root is a shared block placement.
     *
     * Conversion re-parents $root with a single UPDATE and never rewrites what
     * hangs below it, so no descendant's own validation fires — the no-nesting
     * rule has to be evaluated here, over the whole subtree, or a nested
     * placement lands inside the new block unchallenged. It would then fail
     * SHARED_NESTING on the block's first publish, leaving a block that can
     * never be published and can only be repaired by hand.
     *
     * Breadth-first so the cost is one query per level (three or four), and
     * through the repository so each level stays pair-matched on
     * ParentClass + ParentID.
     */
    private function containsPlacement(GridElement $root): bool
    {
        /** @var positive-int $rootId */
        $rootId = (int) $root->ID;

        /** @var array<class-string, list<positive-int>> $frontier */
        $frontier = [$root::class => [$rootId]];

        while ($frontier !== []) {
            $children = $this->elementRepository->findByParents($frontier);
            $frontier = [];

            foreach ($children as $child) {
                if ($child instanceof SharedBlockReference) {
                    return true;
                }

                /** @var positive-int $childId */
                $childId = (int) $child->ID;
                $frontier[$child::class][] = $childId;
            }
        }

        return false;
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

        return Transactional::run(fn(): Result => WriteResult::from(
            fn(): GridElement => $this->replaceWithCopy($reference, $root),
        ));
    }

    /**
     * The detach itself, without a transaction of its own so {@see delete()}
     * can run it many times inside one.
     */
    private function replaceWithCopy(SharedBlockReference $reference, GridElement $root): GridElement
    {
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
    }

    /**
     * Retire $block from the library, resolving what happens to the content on
     * every page that places it.
     *
     * Both modes reach live without the consuming pages being republished,
     * which is what makes the operation usable at all: the alternative — making
     * the author clear every placement by hand first — scales with the size of
     * the site.
     *
     * @return Result<int<0, max>> Placements handled.
     */
    #[NoDiscard('The Result carries the number of placements handled or the reason the delete was refused.')]
    public function delete(SharedBlock $block, SharedBlockDeleteMode $mode): Result
    {
        return Transactional::run(fn(): Result => WriteResult::from(function () use ($block, $mode): int {
            /** @var list<SharedBlockReference> $references */
            $references = SharedBlockReference::get()->filter(['BlockID' => $block->ID])->toArray();

            if ($mode === SharedBlockDeleteMode::Unshare) {
                $this->unshareAll($block, $references);
            }

            // Placements the unshare path did not consume — all of them in
            // Remove mode, plus any live-only stragglers in either — are
            // cleaned up by SharedBlock::onBeforeDelete().
            $block->doArchive();

            return count($references);
        }));
    }

    /**
     * @param list<SharedBlockReference> $references
     * @throws ValidationException When the block has no content left to copy.
     */
    private function unshareAll(SharedBlock $block, array $references): void
    {
        if ($references === []) {
            return;
        }

        $root = $block->getRootElement();

        if ($root === null) {
            // Nothing to copy, so "keep the content" cannot be honoured. Saying
            // so beats silently degrading into the destructive mode.
            throw ValidationException::create(_t(
                self::class . '.UNSHARE_EMPTY_BLOCK',
                'This block has no content left to keep. Delete it and remove the placements instead.',
            ));
        }

        foreach ($references as $reference) {
            // Read before the detach archives the reference: the copy has to
            // reach live wherever the placement already was, or "each page
            // keeps its own copy" would be false on every published page until
            // someone happened to republish it.
            $wasLive = $reference->isPublished();

            $copy = $this->replaceWithCopy($reference, $root);

            if ($wasLive) {
                $copy->publishRecursive();
            }
        }
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
