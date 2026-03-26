<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Repository\GridElementRepositoryInterface;

/**
 * Builds a recursive element tree for a page using batch-loading to avoid N+1 queries.
 *
 * Uses a breadth-first loading strategy: one query per hierarchy depth level.
 * Collects all elements at each level in a single query, then assembles the tree
 * in-memory from the pre-loaded data.
 */
class GridTreeBuilder
{
    use Injectable;

    public function __construct(
        private readonly GridElementRepositoryInterface $elementRepository,
        private readonly GridNodeMapper $nodeMapper,
    ) {
    }

    /**
     * Build the full element tree for a page, keyed by parent ID.
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

        /** @var array<int, list<GridNode>> $tree */
        $tree = [];
        $tree[$pageId] = $this->assembleSubTree($elementsByParent, $rootKey, $pageId);

        return $tree;
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
     * @param array<string, list<GridElement>> $elementsByParent
     * @param positive-int $parentId Numeric parent ID for the GridNode
     * @return list<GridNode>
     */
    private function assembleSubTree(array $elementsByParent, string $parentKey, int $parentId): array
    {
        $nodes = [];

        foreach ($elementsByParent[$parentKey] ?? [] as $element) {
            if (!$element->canView()) {
                continue;
            }

            $nodes[] = $this->buildElementNode($element, $elementsByParent, $parentId);
        }

        return $nodes;
    }

    /**
     * Build a single element node with base fields and optional container fields.
     *
     * @param array<string, list<GridElement>> $elementsByParent
     * @param positive-int $parentId
     */
    private function buildElementNode(GridElement $element, array $elementsByParent, int $parentId): GridNode
    {
        $containerType = null;
        $allowedTypes = null;
        $children = null;
        $gridSettings = null;

        if ($element instanceof ContainerInterface) {
            /** @var positive-int $elementId */
            $elementId = (int) $element->ID;
            $childKey = $element::class . ':' . $elementId;

            $containerType = $element->getContainerType();
            $allowedTypes = $this->nodeMapper->getAllowedTypes($element);
            $children = $this->assembleSubTree($elementsByParent, $childKey, $elementId);
        }

        if ($element instanceof Column) {
            $gridSettings = $element->getGridSettings();
        }

        return $this->nodeMapper->mapToNode($element, $parentId, $containerType, $allowedTypes, $children, $gridSettings);
    }
}
