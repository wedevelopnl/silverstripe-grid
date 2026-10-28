<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use NoDiscard;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Contract\ReorderValidatorInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\Row;
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
 *
 * @phpstan-type LocaleUsage array{
 *     locale: Locale|null,
 *     references: list<SharedBlockReference>,
 *     held: bool,
 *     root: GridElement|null,
 *     pages: list<SiteTree>,
 *     mayDelete: bool,
 * }
 */
class SharedBlockService
{
    public function __construct(
        private readonly ReorderValidatorInterface $validator,
        private readonly ElementPlacementService $placementService,
        private readonly GridElementRepositoryInterface $elementRepository,
        private readonly SharedBlockLocaliser $localiser,
        private readonly LocaleScope $locales,
    ) {
    }

    /**
     * Create a library block seeded with its single root element.
     *
     * Block and root are written together because a block without a root is a
     * broken state, not an intermediate one: it resolves no effective root
     * class, so every page placing it renders nothing, and placing, moving or
     * detaching it fails with BLOCK_EMPTY.
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
        // Refused here rather than left to the model's own zone rule, which
        // would only fire on write — after the localiser below has already
        // given the block content in this locale for a placement that never
        // lands. The parser cannot do it: it does not know the parent's class.
        if ($parent instanceof SiteTree && $zone === '') {
            return Result::fail(new ValidationError(
                message: 'A shared block placed directly on a page needs a page area.',
                field: 'zone',
                key: self::class . '.ZONE_REQUIRED',
            ));
        }

        // Before validation, not after: a block with no content in the active
        // locale resolves no placement class, so the validator would refuse the
        // placement outright with BLOCK_EMPTY. Placing a block that exists only
        // in another locale is an ordinary thing to do while translating a site,
        // so the locale copy is materialised first and the placement then reads
        // as a normal one. A no-op without Fluent, and when the locale already
        // holds content.
        $this->localiser->ensureLocalised($block);

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
            $zone = (string) $root->Zone;
            $parentId = (int) $root->ParentID;
            $parentClass = (string) $root->ParentClass;

            $root->ParentID = $block->ID;
            $root->ParentClass = SharedBlock::class;

            // Zone is placement data and moves to the reference with it.
            $root->Zone = '';

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
        $copy = $this->withoutScaffolding(static function () use ($reference, $root): GridElement {
            // cascade_duplicates carries the whole subtree; the copy comes
            // back still pointing at the block, so re-point it immediately.
            $copy = $root->duplicate(true);

            $copy->ParentID = $reference->ParentID;
            $copy->ParentClass = $reference->ParentClass;
            $copy->Sort = $reference->Sort;

            // The copy lands exactly where the reference sat, so it inherits
            // the reference's Zone — '' whenever that was mid-tree.
            $copy->Zone = (string) $reference->Zone;

            $copy->write();

            return $copy;
        });

        $reference->doArchive();

        return $copy;
    }

    /**
     * The cascade copies real children, so auto-scaffolding would only add
     * extras — an empty Row under a Section the block holds empty, written by
     * the duplicate or by the re-pointing write after it. Captured and restored
     * explicitly, as {@see SharedBlockLocaliser} does: an unrestored override
     * would leak for the rest of the request.
     *
     * @param callable(): GridElement $write
     */
    private function withoutScaffolding(callable $write): GridElement
    {
        /** @var bool $priorSectionScaffold */
        $priorSectionScaffold = Section::config()->get('auto_scaffold');
        /** @var bool $priorRowScaffold */
        $priorRowScaffold = Row::config()->get('auto_scaffold');

        Config::modify()->set(Section::class, 'auto_scaffold', false);
        Config::modify()->set(Row::class, 'auto_scaffold', false);

        try {
            return $write();
        } finally {
            Config::modify()->set(Section::class, 'auto_scaffold', $priorSectionScaffold);
            Config::modify()->set(Row::class, 'auto_scaffold', $priorRowScaffold);
        }
    }

    /**
     * Retire $block from the library, resolving what happens to the content on
     * every page that places it.
     *
     * The block is one record for the whole site while its placements and its
     * content are stored per locale, so both modes act on EVERY locale, whichever
     * one is active: a Remove leaves no placement and no block content behind in
     * any locale, an Unshare gives each page in each locale a copy of that
     * locale's content.
     *
     * Both modes reach live without the consuming pages being republished,
     * which is what makes the operation usable at all: the alternative — making
     * the author clear every placement by hand first — scales with the size of
     * the site. Each consuming page still gets a new draft version recording
     * the change, in each of its locales, inside the same transaction: a
     * failure anywhere leaves block, placements and pages as they were.
     *
     * Reaching every locale is also why a member restricted to some locales is
     * refused, before anything is written, unless they may delete the block in
     * each locale holding a placement or block content of it.
     *
     * @return Result<int<0, max>> Placements handled, across every locale.
     */
    #[NoDiscard('The Result carries the number of placements handled or the reason the delete was refused.')]
    public function delete(SharedBlock $block, SharedBlockDeleteMode $mode): Result
    {
        return Transactional::run(function () use ($block, $mode): Result {
            $usage = $this->usageByLocale($block);

            $refusal = $this->refuseUneditableLocales($block, $usage);
            if ($refusal !== null) {
                return Result::fail($refusal);
            }

            return WriteResult::from(function () use ($block, $mode, $usage): int {
                if ($mode === SharedBlockDeleteMode::Unshare) {
                    $this->unshareAll($usage);
                }

                // Placements the unshare path did not consume — all of them in
                // Remove mode, those of a locale without content, and any
                // live-only stragglers — go with the block, in every locale:
                // SharedBlock::onBeforeDelete() cleans them up.
                $block->doArchive();

                $this->recordOnConsumingPages($usage);

                return array_sum(array_map(
                    static fn (array $locale): int => count($locale['references']),
                    $usage,
                ));
            });
        });
    }

    /**
     * What each locale holds of $block, read before anything changes.
     *
     * @return array<string, LocaleUsage>
     */
    private function usageByLocale(SharedBlock $block): array
    {
        $blockId = (int) $block->ID;

        return $this->locales->each(static function (?Locale $locale) use ($block, $blockId): array {
            /** @var list<SharedBlockReference> $references */
            $references = SharedBlockReference::get()->filter(['BlockID' => $blockId])->toArray();

            /** @var array<int, SiteTree> $pages */
            $pages = [];

            foreach ($references as $reference) {
                $page = $reference->getPage();

                if ($page instanceof SiteTree) {
                    $pages[$page->ID] = $page;
                }
            }

            $root = $block->getRootElement();

            // Both modes delete this locale's placements and its block content,
            // so either one, on either stage, makes the locale one the delete
            // writes into.
            $held = $references !== [] || $root !== null || Versioned::withVersionedMode(
                static function () use ($blockId): bool {
                    Versioned::set_stage(Versioned::LIVE);

                    return SharedBlockReference::get()->filter(['BlockID' => $blockId])->exists()
                        || GridElement::get()->filter([
                            'ParentID' => $blockId,
                            'ParentClass' => SharedBlock::class,
                        ])->exists();
                },
            );

            // Fluent drops a locale-restricted group from the member while
            // another locale is active, and Permission caches per member, so
            // the cache has to go for the answer to be this locale's.
            Permission::reset();

            return [
                'locale' => $locale,
                'references' => $references,
                'held' => $held,
                'root' => $root,
                'pages' => array_values($pages),
                'mayDelete' => $locale === null || $block->canDelete(),
            ];
        });
    }

    /**
     * The delete writes into every locale holding a placement or block
     * content, so a member restricted to some locales would otherwise reach the
     * others through it — a translation not placed anywhere yet included. They
     * need Fluent's multi-locale permission, or the right to delete the block
     * with each locale concerned active.
     *
     * Without a member (a task, project code outside a request) there is no
     * editor whose locales could be restricted: the call goes through, as a
     * bare delete() of the record does. The CMS form never gets here without
     * one, since it requires canDelete() first.
     *
     * @param array<string, LocaleUsage> $usage
     */
    private function refuseUneditableLocales(SharedBlock $block, array $usage): ?ValidationError
    {
        if (!$this->locales->isLocalised() || Security::getCurrentUser() === null) {
            return null;
        }

        // The per-locale checks in usageByLocale() left the cache holding the
        // last locale's answer; this check belongs to the active one.
        Permission::reset();

        if (Permission::check(Locale::CMS_ACCESS_MULTI_LOCALE)) {
            return null;
        }

        $denied = [];

        foreach ($usage as $locale) {
            if ($locale['held'] && !$locale['mayDelete'] && $locale['locale'] !== null) {
                $denied[] = $locale['locale']->Title;
            }
        }

        if ($denied === []) {
            return null;
        }

        return new ValidationError(
            message: 'You can\'t delete "{title}": it also has content or placements in {locales},'
                . ' which you may not edit. Ask someone who can edit every language this block is in.',
            key: self::class . '.LOCALE_PERMISSION',
            params: ['title' => (string) $block->Title, 'locales' => implode(', ', $denied)],
        );
    }

    /**
     * Copy each locale's content onto that locale's placements.
     *
     * A locale that holds placements but no content gets no copy: its
     * placements render nothing today, so leaving them to the block's own
     * cleanup keeps those pages showing exactly what they show. Only when no
     * placed locale has content at all is the mode refused.
     *
     * @param array<string, LocaleUsage> $usage
     * @throws ValidationException When no locale has content left to copy.
     */
    private function unshareAll(array $usage): void
    {
        $placed = array_filter($usage, static fn (array $locale): bool => $locale['references'] !== []);

        if ($placed === []) {
            return;
        }

        if (array_filter($placed, static fn (array $locale): bool => $locale['root'] !== null) === []) {
            // Nothing to copy, so "keep the content" cannot be honoured. Saying
            // so beats silently degrading into the destructive mode.
            throw ValidationException::create(_t(
                self::class . '.UNSHARE_EMPTY_BLOCK',
                'This block has no content left to keep. Delete it and remove the placements instead.',
            ));
        }

        $this->locales->each(function (?Locale $locale) use ($placed): void {
            $usage = $placed[(string) $locale?->Locale] ?? null;
            $root = $usage['root'] ?? null;

            if ($usage === null || $root === null) {
                return;
            }

            foreach ($usage['references'] as $reference) {
                // Read before the detach archives the reference: the copy has
                // to reach live wherever the placement already was, or "each
                // page keeps its own copy" would be false on every published
                // page until someone happened to republish it.
                $wasLive = $reference->isPublished();

                $copy = $this->replaceWithCopy($reference, $root);

                if ($wasLive) {
                    $copy->publishRecursive();
                }
            }
        });
    }

    /**
     * The consuming pages now render something else, so each needs a draft
     * version recording that — in the locale whose placement changed.
     *
     * @param array<string, LocaleUsage> $usage
     */
    private function recordOnConsumingPages(array $usage): void
    {
        $this->locales->each(static function (?Locale $locale) use ($usage): void {
            foreach ($usage[(string) $locale?->Locale]['pages'] ?? [] as $page) {
                $page->writeToStage(Versioned::DRAFT);
            }
        });
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
