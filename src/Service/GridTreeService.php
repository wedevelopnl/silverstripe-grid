<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;
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
     * Build the full element tree for a page, keyed by page ID for backwards
     * compatibility with callers that still expect a top-level map (the wire
     * format emitted by {@see GridController::apiReadTree()} reshapes this
     * into a structured `{rootParent, nodes}` response).
     *
     * @param non-empty-string $zone
     * @return array<int, list<GridNode>>
     */
    public function buildForPage(SiteTree $page, string $zone = 'main'): array
    {
        /** @var positive-int $pageId */
        $pageId = $page->ID;

        $elementsByParent = $this->loadAllElements($pageId, $page::class, $zone);

        $rootKey = $page::class . ':' . $pageId;
        $rootParent = new NodeRef(NodeType::fromClass($page::class), $pageId);

        /** @var array<int, list<GridNode>> $tree */
        $tree = [];
        $tree[$pageId] = $this->assembleSubTree($elementsByParent, $rootKey, $rootParent, $page);

        return $tree;
    }

    /**
     * Find all Column elements for a page + zone using batch loading.
     *
     * Reuses the same breadth-first loading strategy as {@see buildForPage()}
     * but returns only the Column model instances, needed for bulk grid
     * settings operations like viewport override resets.
     *
     * @param non-empty-string $zone
     * @return list<Column>
     */
    public function findColumnsForPage(SiteTree $page, string $zone): array
    {
        /** @var positive-int $pageId */
        $pageId = $page->ID;

        $elementsByParent = $this->loadAllElements($pageId, $page::class, $zone);

        /** @var list<Column> $columns */
        $columns = [];
        foreach ($elementsByParent as $elements) {
            foreach ($elements as $element) {
                if ($element instanceof Column) {
                    $columns[] = $element;
                }
            }
        }

        return $columns;
    }

    /**
     * Find containers of a given type on a page + zone as plain tuples.
     *
     * Much cheaper than {@see buildForPage()} when the caller only needs a
     * flat list of containers (e.g. "which Rows could I duplicate into?"):
     * skips permission probing on non-target nodes, grid settings, block
     * schemas, and DTO assembly.
     *
     * Still runs through the same breadth-first loader so polymorphic parent
     * keying and zone scoping at the Section level match the full tree
     * build. Only viewable elements are included — callers use the output
     * as a UI list and must not see containers the user can't view.
     *
     * @param non-empty-string $zone
     * @return list<array{id: positive-int, title: string, type: string}>
     */
    public function findContainersOfType(SiteTree $page, string $zone, ContainerType $type): array
    {
        /** @var positive-int $pageId */
        $pageId = $page->ID;

        $elementsByParent = $this->loadAllElements($pageId, $page::class, $zone);
        $targetClass = $type->toElementClass();
        $typeValue = $type->value;

        /** @var list<array{id: positive-int, title: string, type: string}> $containers */
        $containers = [];
        foreach ($elementsByParent as $elements) {
            foreach ($elements as $element) {
                if (!$element instanceof $targetClass || !$element->canView()) {
                    continue;
                }
                /** @var positive-int $elementId */
                $elementId = (int) $element->ID;
                /** @var non-empty-string $title '(untitled)' fallback guarantees non-empty */
                $title = $element->Title ?: _t(
                    GridElement::class . '.UNTITLED',
                    '(untitled)',
                );
                $containers[] = [
                    'id' => $elementId,
                    'title' => $title,
                    'type' => $typeValue,
                ];
            }
        }

        return $containers;
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
     * @param non-empty-string $zone
     * @return array<string, list<GridElement>> Map of "ParentClass:ParentID" → elements
     */
    private function loadAllElements(int $rootParentId, string $rootParentClass, string $zone): array
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
