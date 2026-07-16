<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridTree;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

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
     * @param non-empty-string $zone
     */
    public function buildViewableTree(SiteTree $page, string $zone): GridTree
    {
        /** @var positive-int $pageId */
        $pageId = $page->ID;

        $elementsByParent = $this->loadAllElements($pageId, $page::class, $zone);

        $rootKey = $page::class . ':' . $pageId;
        $rootParent = new NodeRef(NodeType::fromClass($page::class), $pageId);

        return new GridTree(
            $rootParent,
            $this->assembleSubTree($elementsByParent, $rootKey, $rootParent, $page),
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
     * Recursively assemble tree nodes from pre-loaded element data.
     *
     * The already-loaded $parentObject is seeded into each element's Parent
     * has_one component before {@see GridElement::canView()} runs, so the
     * permission check's {@see GridElement::getPage()} walk resolves entirely
     * in-memory instead of issuing a fresh ORM fetch per node (avoids N+1).
     *
     * @param array<string, list<GridElement>> $elementsByParent
     * @return list<GridNode>
     */
    private function assembleSubTree(
        array $elementsByParent,
        string $parentKey,
        NodeRef $parent,
        DataObject $parentObject,
    ): array {
        $nodes = [];

        foreach ($elementsByParent[$parentKey] ?? [] as $element) {
            $element->setComponent('Parent', $parentObject);

            if (!$element->canView()) {
                continue;
            }

            $nodes[] = $this->buildElementNode($element, $elementsByParent, $parent);
        }

        return $nodes;
    }

    /**
     * Build a single element node: assemble children for containers, then
     * delegate all node content to the mapper.
     *
     * @param array<string, list<GridElement>> $elementsByParent
     */
    private function buildElementNode(GridElement $element, array $elementsByParent, NodeRef $parent): GridNode
    {
        $children = null;

        if ($element instanceof ContainerInterface) {
            /** @var positive-int $elementId */
            $elementId = (int) $element->ID;
            $childKey = $element::class . ':' . $elementId;
            $selfRef = new NodeRef(NodeType::fromClass($element::class), $elementId);

            $children = $this->assembleSubTree($elementsByParent, $childKey, $selfRef, $element);
        }

        return $this->nodeMapper->mapToNode($element, $parent, $children);
    }
}
