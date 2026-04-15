<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injectable;
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
class GridTreeBuilder
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
        $tree[$pageId] = $this->assembleSubTree($elementsByParent, $rootKey, $rootParent);

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
     * Recursively collect containers matching the target type from a pre-built tree.
     *
     * @param list<GridNode> $nodes
     * @return list<array{id: positive-int, title: string, type: string}>
     */
    public static function collectContainersOfType(array $nodes, ContainerType $targetType): array
    {
        /** @var list<array{id: positive-int, title: string, type: string}> $containers */
        $containers = [];
        self::doCollectContainers($nodes, $targetType, $containers);

        return $containers;
    }

    /**
     * Walk a pre-built tree and count how many columns have overrides per viewport,
     * plus a total count of columns with any overrides.
     *
     * @param list<GridNode> $nodes
     * @return array<non-empty-string, int>
     */
    public static function countOverrides(array $nodes): array
    {
        /** @var array<non-empty-string, int> $counts */
        $counts = [];
        self::doCountOverrides($nodes, $counts);

        return $counts;
    }

    /**
     * @param list<GridNode> $nodes
     * @param list<array{id: positive-int, title: string, type: string}> $containers
     */
    private static function doCollectContainers(array $nodes, ContainerType $targetType, array &$containers): void
    {
        foreach ($nodes as $node) {
            if ($node->containerType === $targetType) {
                $containers[] = [
                    'id' => $node->getId(),
                    'title' => $node->title,
                    'type' => $targetType->value,
                ];
            }

            if ($node->children !== null) {
                self::doCollectContainers($node->children, $targetType, $containers);
            }
        }
    }

    /**
     * @param list<GridNode> $nodes
     * @param array<non-empty-string, int> $counts
     */
    private static function doCountOverrides(array $nodes, array &$counts): void
    {
        foreach ($nodes as $node) {
            if ($node->gridSettings !== null && $node->gridSettings->overrides !== []) {
                $counts['_total'] = ($counts['_total'] ?? 0) + 1;

                foreach (array_keys($node->gridSettings->overrides) as $viewport) {
                    $counts[$viewport] = ($counts[$viewport] ?? 0) + 1;
                }
            }

            if ($node->children !== null) {
                self::doCountOverrides($node->children, $counts);
            }
        }
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
     * @return list<GridNode>
     */
    private function assembleSubTree(array $elementsByParent, string $parentKey, NodeRef $parent): array
    {
        $nodes = [];

        foreach ($elementsByParent[$parentKey] ?? [] as $element) {
            if (!$element->canView()) {
                continue;
            }

            $nodes[] = $this->buildElementNode($element, $elementsByParent, $parent);
        }

        return $nodes;
    }

    /**
     * Build a single element node with base fields and optional container fields.
     *
     * @param array<string, list<GridElement>> $elementsByParent
     */
    private function buildElementNode(GridElement $element, array $elementsByParent, NodeRef $parent): GridNode
    {
        $containerType = null;
        $allowedTypes = null;
        $children = null;
        $gridSettings = null;

        if ($element instanceof ContainerInterface) {
            /** @var positive-int $elementId */
            $elementId = (int) $element->ID;
            $childKey = $element::class . ':' . $elementId;
            $selfRef = new NodeRef(NodeType::fromClass($element::class), $elementId);

            $containerType = $element->getContainerType();
            $allowedTypes = $this->nodeMapper->getAllowedTypes($element);
            $children = $this->assembleSubTree($elementsByParent, $childKey, $selfRef);
        }

        if ($element instanceof Column) {
            $gridSettings = $element->getGridSettings();
        }

        return $this->nodeMapper->mapToNode($element, $parent, $containerType, $allowedTypes, $children, $gridSettings);
    }
}
