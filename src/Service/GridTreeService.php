<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Model\SharedBlock;
use WeDevelop\Grid\Model\SharedBlockReference;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridTree;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\SharedBlockMeta;
use WeDevelop\Grid\Value\SharedBlockStatus;

/**
 * Builds a recursive element tree for a page using batch-loading to avoid N+1 queries.
 *
 * Uses a breadth-first loading strategy: one query per hierarchy depth level.
 * Collects all elements at each level in a single query, then assembles the tree
 * in-memory from the pre-loaded data.
 */
class GridTreeService
{
    use Injectable;

    public function __construct(
        private readonly GridElementRepositoryInterface $elementRepository,
        private readonly GridNodeMapper $nodeMapper,
        private readonly SharedBlockUsageResolver $usageResolver,
    ) {
    }

    /**
     * Assemble the viewable element tree for a page + zone as the CMS React
     * API's wire model. Renamed from buildForPage: the old name implied
     * page/template rendering — this never touches .ss templates.
     *
     * Applies canView() filtering per node ({@see assembleSubTree()}); the
     * mechanism-layer methods never filter.
     *
     * Two hosts, one editor: a page + zone, or a SharedBlock whose single
     * subtree is the library's editable content. Zones scope page roots only,
     * so a block root ignores $zone.
     *
     * @param SiteTree|SharedBlock $root
     * @param string $zone Ignored for a SharedBlock root, which owns one subtree.
     */
    public function buildViewableTree(DataObject $root, string $zone): GridTree
    {
        /** @var positive-int $rootId */
        $rootId = (int) $root->ID;

        $zoneFilter = null;
        if (!$root instanceof SharedBlock && $zone !== '') {
            $zoneFilter = $zone;
        }

        $elementsByParent = $this->loadAllElements($rootId, $root::class, $zoneFilter);

        $this->prepopulateVersionNumberCache($elementsByParent);

        $rootKey = $root::class . ':' . $rootId;
        $rootParent = new NodeRef(NodeType::fromClass($root::class), $rootId);

        // One block placed N times on a page must cost one subtree load, so the
        // expansion memo spans the whole traversal.
        /** @var array<int, array<string, list<GridElement>>> $blockSubtrees */
        $blockSubtrees = [];

        return new GridTree(
            $rootParent,
            $this->assembleSubTree($elementsByParent, $rootKey, $rootParent, $root, $blockSubtrees),
            $this->nodeMapper->allowedTypesByContainerType(),
        );
    }

    /**
     * Flat list of every element on a page + zone (Sections included), in
     * breadth-first level order with siblings in Sort order. Mechanism layer:
     * never filters by permissions.
     *
     * @param non-empty-string $zone
     * @return list<GridElement>
     */
    public function findDescendantsForPage(SiteTree $page, string $zone): array
    {
        /** @var positive-int $pageId */
        $pageId = $page->ID;

        return $this->flatten($this->loadAllElements($pageId, $page::class, $zone));
    }

    /**
     * Flat subtree below any element via the same repository BFS, in level
     * order. The root itself is NOT included. No zone filter — zone scopes
     * page→section only, and an element root is already inside a zone.
     * Mechanism layer: never filters by permissions.
     *
     * $root must be a persisted element — an unwritten root (ID 0) has no
     * children to find.
     *
     * @return list<GridElement>
     */
    public function findDescendants(GridElement $root): array
    {
        /** @var positive-int $rootId */
        $rootId = (int) $root->ID;

        return $this->flatten($this->loadAllElements($rootId, $root::class, null));
    }

    /**
     * Containers of the given type on a page + zone that the current user may
     * view. Policy layer: callers use the output as a UI list and must not see
     * containers the user can't view.
     *
     * @param non-empty-string $zone
     * @return list<GridElement>
     */
    public function findViewableContainersOfType(SiteTree $page, string $zone, ContainerType $type): array
    {
        $targetClass = $type->toElementClass();

        /** @var list<GridElement> $containers */
        $containers = [];
        foreach ($this->findDescendantsForPage($page, $zone) as $element) {
            if ($element instanceof $targetClass && $element->canView()) {
                $containers[] = $element;
            }
        }

        return $containers;
    }

    /**
     * Index elements by their own "Class:ID" key for O(1) ancestor lookup.
     *
     * Pass every element the caller cares about (typically the full result set):
     * an ancestor missing from the index simply terminates the walk early.
     *
     * @param iterable<GridElement> $elements
     * @return array<string, GridElement>
     */
    public function indexByKey(iterable $elements): array
    {
        $index = [];
        foreach ($elements as $element) {
            $index[$element::class . ':' . $element->ID] = $element;
        }

        return $index;
    }

    /**
     * The element's ancestor containers, outermost first (Section → Row → Column).
     *
     * The element's own level and the owning page are excluded — the page is not
     * a GridElement. Mechanism layer: never filters by permissions.
     *
     * @param array<string, GridElement> $index keyed by "Class:ID" (see {@see indexByKey()})
     * @return list<GridElement>
     */
    public function ancestors(GridElement $element, array $index): array
    {
        $ancestors = [];
        $parentKey = $element->ParentClass . ':' . $element->ParentID;

        while (isset($index[$parentKey])) {
            $parent = $index[$parentKey];
            $ancestors[] = $parent;
            $parentKey = $parent->ParentClass . ':' . $parent->ParentID;
        }

        return array_reverse($ancestors);
    }

    /**
     * Seed every indexed element's polymorphic Parent component in-memory so
     * later getPage()/getCMSEditLink() walks resolve without a database fetch
     * each — the same technique {@see assembleSubTree()} applies during tree
     * assembly, where the parent is the recursion cursor. Here the index is
     * flat and page-less, so element parents resolve through the index and
     * owning pages are batch-loaded in a single query.
     *
     * Mechanism layer: never filters by permissions.
     *
     * @param array<string, GridElement> $index keyed by "Class:ID" (see {@see indexByKey()})
     */
    public function seedParents(array $index): void
    {
        /** @var list<int> $pageIds */
        $pageIds = [];
        foreach ($index as $element) {
            if (isset($index[$element->ParentClass . ':' . $element->ParentID])) {
                continue;
            }

            $parentClass = (string) $element->ParentClass;
            if ((int) $element->ParentID > 0 && is_a($parentClass, SiteTree::class, true)) {
                $pageIds[] = (int) $element->ParentID;
            }
        }

        /** @var array<int, SiteTree> $pagesById */
        $pagesById = [];
        if ($pageIds !== []) {
            foreach (SiteTree::get()->byIDs($pageIds) as $sitePage) {
                $pagesById[(int) $sitePage->ID] = $sitePage;
            }
        }

        foreach ($index as $element) {
            $parentKey = $element->ParentClass . ':' . $element->ParentID;
            if (isset($index[$parentKey])) {
                $element->setComponent('Parent', $index[$parentKey]);
                continue;
            }

            $page = $pagesById[(int) $element->ParentID] ?? null;
            // Match the polymorphic component fetch this replaces: a
            // class-scoped lookup only returns records of ParentClass or a
            // subclass. Anything else (orphan, non-SiteTree owner, class
            // mismatch) is left unseeded — getPage() falls back to the
            // per-element fetch for those rare rows, preserving behavior.
            if ($page !== null && is_a($page, (string) $element->ParentClass)) {
                $element->setComponent('Parent', $page);
            }
        }
    }

    /**
     * @param array<string, list<GridElement>> $elementsByParent
     * @return list<GridElement>
     */
    private function flatten(array $elementsByParent): array
    {
        /** @var list<GridElement> $flat */
        $flat = [];
        foreach ($elementsByParent as $elements) {
            foreach ($elements as $element) {
                $flat[] = $element;
            }
        }

        return $flat;
    }

    /**
     * Breadth-first batch loading: one query per hierarchy depth level.
     *
     * Filters by both ParentID and ParentClass to avoid false matches when
     * a page ID coincides with a GridElement ID (they share no ID namespace
     * separation after the ElementalArea intermediary was removed).
     *
     * Elements are keyed by a composite "ParentClass:ParentID" string to
     * prevent collisions when a page ID equals a GridElement ID.
     *
     * Zone filtering is applied only at the root level (page → sections).
     * Child elements are scoped by their container parent, not by zone.
     *
     * @param positive-int $rootParentId
     * @param class-string $rootParentClass
     * @param non-empty-string|null $zone null = no zone filter (element-rooted loads)
     * @return array<string, list<GridElement>> Map of "ParentClass:ParentID" → elements
     */
    private function loadAllElements(int $rootParentId, string $rootParentClass, ?string $zone): array
    {
        /** @var array<string, list<GridElement>> $elementsByParent */
        $elementsByParent = [];

        /** @var array<class-string, list<positive-int>> $parentIdsByClass */
        $parentIdsByClass = [$rootParentClass => [$rootParentId]];

        $isRootLevel = true;

        while ($parentIdsByClass !== []) {
            $elements = $this->elementRepository->findByParents(
                $parentIdsByClass,
                $isRootLevel ? $zone : null,
            );
            $isRootLevel = false;
            $parentIdsByClass = [];

            foreach ($elements as $element) {
                $key = $element->ParentClass . ':' . $element->ParentID;
                $elementsByParent[$key] ??= [];
                $elementsByParent[$key][] = $element;

                if ($element instanceof ContainerInterface) {
                    /** @var positive-int $elementId */
                    $elementId = $element->ID;
                    $parentIdsByClass[$element::class] ??= [];
                    $parentIdsByClass[$element::class][] = $elementId;
                }
            }
        }

        return $elementsByParent;
    }

    /**
     * Batch-fill Versioned's version-number cache for every loaded element.
     *
     * The node mapper's per-node status and permission checks (getStatusFlags,
     * canUnpublish → isPublished/isOnDraft/stagesDiffer) each resolve through
     * Versioned::get_versionnumber_by_stage(), which issues one
     * `SELECT Version … WHERE ID = ?` per element per stage on a cache miss —
     * an N+1 hidden behind the batch loading above. All GridElement subclasses
     * share one base table, so two queries fill the cache for the whole tree.
     * get_versionnumber_by_stage always reads the stage tables regardless of
     * reading mode, so this is equally valid under archived-version reads.
     *
     * @param array<string, list<GridElement>> $elementsByParent
     */
    private function prepopulateVersionNumberCache(array $elementsByParent): void
    {
        $ids = [];
        foreach ($elementsByParent as $elements) {
            foreach ($elements as $element) {
                $ids[] = (int) $element->ID;
            }
        }

        // An empty ID list means "cache the entire table" to the vendor API —
        // never intended here, so bail instead.
        if ($ids === []) {
            return;
        }

        Versioned::prepopulateVersionNumberCache(GridElement::class, $ids);
    }

    /**
     * Recursively assemble tree nodes from pre-loaded element data.
     *
     * The already-loaded $parentObject is seeded into each element's Parent
     * has_one component before {@see GridElement::canView()} runs, so the
     * permission check's {@see GridElement::getPage()} walk resolves entirely
     * in-memory instead of issuing a fresh ORM fetch per node (avoids N+1).
     *
     * @param array<string, list<GridElement>> $elementsByParent
     * @param array<int, array<string, list<GridElement>>> $blockSubtrees
     * @return list<GridNode>
     */
    private function assembleSubTree(
        array $elementsByParent,
        string $parentKey,
        NodeRef $parent,
        DataObject $parentObject,
        array &$blockSubtrees,
    ): array {
        $nodes = [];

        foreach ($elementsByParent[$parentKey] ?? [] as $element) {
            $element->setComponent('Parent', $parentObject);

            if (!$element->canView()) {
                continue;
            }

            $nodes[] = $this->buildElementNode($element, $elementsByParent, $parent, $blockSubtrees);
        }

        return $nodes;
    }

    /**
     * Build a single element node: assemble children for containers, then
     * delegate all node content to the mapper.
     *
     * @param array<string, list<GridElement>> $elementsByParent
     * @param array<int, array<string, list<GridElement>>> $blockSubtrees
     */
    private function buildElementNode(
        GridElement $element,
        array $elementsByParent,
        NodeRef $parent,
        array &$blockSubtrees,
    ): GridNode {
        if ($element instanceof SharedBlockReference) {
            return $this->buildReferenceNode($element, $parent, $blockSubtrees);
        }

        $children = null;

        if ($element instanceof ContainerInterface) {
            /** @var positive-int $elementId */
            $elementId = (int) $element->ID;
            $childKey = $element::class . ':' . $elementId;
            $selfRef = new NodeRef(NodeType::fromClass($element::class), $elementId);

            $children = $this->assembleSubTree($elementsByParent, $childKey, $selfRef, $element, $blockSubtrees);
        }

        return $this->nodeMapper->mapToNode($element, $parent, $children);
    }

    /**
     * Expand a placement into the block's own subtree.
     *
     * The reference keeps an ordinary element identity on the wire, so reorder,
     * delete and publish of the PLACEMENT flow through the existing endpoints
     * unchanged. What marks it out is the sharedBlock meta and its single child:
     * the block's root, a completely normal typed node.
     *
     * @param array<int, array<string, list<GridElement>>> $blockSubtrees
     */
    private function buildReferenceNode(
        SharedBlockReference $reference,
        NodeRef $parent,
        array &$blockSubtrees,
    ): GridNode {
        $block = $reference->Block();

        if ($block === null || !$block->exists() || !$block->canView()) {
            // Two cases, one degradation. A blockless reference is raw DB
            // damage (placement validation never writes one); an unviewable
            // block is a project's updateCanView veto, which SharedBlockMeta
            // would otherwise leak straight past — it carries the block's
            // title, its usage count and its CMS edit link, while the block's
            // own elements ARE filtered by canView() in assembleSubTree().
            // apiPlace() re-checks canView() for exactly this reason.
            //
            // Either way, degrade to a plain node rather than fail the whole
            // tree — the editor still renders the rest of the page.
            return $this->nodeMapper->mapToNode($reference, $parent, null);
        }

        /** @var positive-int $blockId */
        $blockId = (int) $block->ID;

        if (!isset($blockSubtrees[$blockId])) {
            $subtree = $this->loadAllElements($blockId, SharedBlock::class, null);
            $this->prepopulateVersionNumberCache($subtree);
            $blockSubtrees[$blockId] = $subtree;
        }

        $subtree = $blockSubtrees[$blockId];

        /** @var positive-int $referenceId */
        $referenceId = (int) $reference->ID;
        $selfRef = new NodeRef(NodeType::Element, $referenceId);

        $children = $this->assembleSubTree(
            $subtree,
            SharedBlock::class . ':' . $blockId,
            $selfRef,
            $block,
            $blockSubtrees,
        );

        $meta = new SharedBlockMeta(
            $blockId,
            (string) $block->Title,
            $this->usageResolver->usageCount($block),
            $this->aggregateBlockStatus($block, $subtree),
            $block->getCMSEditLink(),
        );

        return $this->nodeMapper->mapToNode($reference, $parent, $children, $meta);
    }

    /**
     * Aggregate publication state of a block, for callers that hold no tree —
     * the library list and the block picker.
     */
    public function blockStatus(SharedBlock $block): SharedBlockStatus
    {
        /** @var positive-int $blockId */
        $blockId = (int) $block->ID;

        return $this->aggregateBlockStatus($block, $this->loadAllElements($blockId, SharedBlock::class, null));
    }

    /**
     * A block is only as published as its least published part, so the badge
     * folds the block record's own state together with every element under it.
     *
     * @param array<string, list<GridElement>> $subtree
     */
    private function aggregateBlockStatus(SharedBlock $block, array $subtree): SharedBlockStatus
    {
        /** @var list<ElementStatus> $statuses */
        $statuses = [];

        foreach ($subtree as $elements) {
            foreach ($elements as $element) {
                /** @var array<string, array{text: string, title: string}> $flags */
                $flags = $element->getStatusFlags();
                $statuses[] = ElementStatus::fromStatusFlags($flags);
            }
        }

        return SharedBlockStatus::compute($block->isPublished(), $block->stagesDiffer(), $statuses);
    }
}
