<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\ContainerType;
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
    use Configurable;
    use Extensible;
    use Injectable;

    /** @var array<class-string, array<class-string, array{label: string, icon: string, description: string}>> */
    private array $allowedTypesCache = [];

    public function __construct(
        private readonly GridElementRepositoryInterface $elementRepository,
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
            $allowedTypes = $this->getAllowedTypes($element);
            $children = $this->assembleSubTree($elementsByParent, $childKey, $elementId);
        }

        if ($element instanceof Column) {
            $gridSettings = $element->getGridSettingsData();
        }

        return $this->createNode($element, $parentId, $containerType, $allowedTypes, $children, $gridSettings);
    }

    /**
     * Map a GridElement to a GridNode DTO.
     *
     * @param positive-int $parentId
     * @param array<class-string, array{label: string, icon: string, description: string}>|null $allowedTypes
     * @param list<GridNode>|null $children
     * @param array<non-empty-string, array{width: positive-int, offset: int<0, max>, visible: bool}>|null $gridSettings
     */
    private function createNode(
        GridElement $element,
        int $parentId,
        ?ContainerType $containerType,
        ?array $allowedTypes,
        ?array $children,
        ?array $gridSettings,
    ): GridNode {
        /** @var non-empty-string $title Fallback '(untitled)' guarantees non-empty */
        $title = $element->Title ?: _t(
            GridElement::class . '.UNTITLED',
            '(untitled)',
        );

        /** @var array{typeName: string, type: string, title: string, summary: string, label: string} $blockSchema */
        $blockSchema = $element->getBlockSchema();
        $blockSchema['label'] = $element->getType();

        $icon = Config::forClass($element::class)->get('icon');

        /** @var array{typeName: string, type: string, title: string, summary: string, label: string, icon: string} $blockSchemaWithIcon */
        $blockSchemaWithIcon = array_merge($blockSchema, [
            'icon' => is_string($icon) && $icon !== '' ? $icon : 'font-icon-block-content',
        ]);

        /** @var array<string, array{text: string, title: string}> $statusFlags */
        $statusFlags = $element->getStatusFlags();

        /** @var array<string, mixed> $extensions */
        $extensions = [];
        $this->extend('updateElementData', $element, $extensions);
        assert($element instanceof GridElement); // extend() passes by-ref, widening the type
        /** @var array<string, mixed> $extensions PHPStan: extend() widens by-ref params */

        /** @var positive-int $id */
        $id = (int) $element->ID;

        $canUnpublish = $element->canUnpublish();
        assert(is_bool($canUnpublish));


        return new GridNode(
            id: $id,
            parentId: $parentId,
            title: $title,
            blockSchema: $blockSchemaWithIcon,
            obsoleteClassName: $element->getObsoleteClassName(),
            version: (int) $element->Version,
            canDelete: $element->canDelete(),
            canPublish: $element->canPublish(),
            canUnpublish: $canUnpublish,
            canCreate: $element->canCreate(),
            editLink: $element->getCMSEditLink(),
            statusFlags: $statusFlags,
            containerType: $containerType,
            allowedTypes: $allowedTypes,
            children: $children,
            gridSettings: $gridSettings,
            extensions: $extensions,
        );
    }

    /**
     * Get allowed child element types for a container, cached by class name.
     *
     * Reads allowed_elements / disallowed_elements config directly.
     *
     * @return array<class-string, array{label: string, icon: string, description: string}>
     */
    private function getAllowedTypes(GridElement $container): array
    {
        $className = $container::class;

        if (isset($this->allowedTypesCache[$className])) {
            return $this->allowedTypesCache[$className];
        }

        $config = Config::forClass($className);
        $stopInheritance = (bool) $config->get('stop_element_inheritance');

        $allowedElements = $stopInheritance
            ? $config->get('allowed_elements', Config::UNINHERITED)
            : $config->get('allowed_elements');

        $disallowedElements = $stopInheritance
            ? (array) $config->get('disallowed_elements', Config::UNINHERITED)
            : (array) $config->get('disallowed_elements');

        $types = [];

        if (is_array($allowedElements)) {
            foreach ($allowedElements as $class) {
                if (is_string($class) && is_subclass_of($class, GridElement::class)) {
                    $types[$class] = $this->getElementTypeInfo($class);
                }
            }
        } else {
            // No allowlist — all GridElement subclasses except disallowed
            foreach (ClassInfo::subclassesFor(GridElement::class, false) as $class) {
                /** @var class-string<GridElement> $class */
                if (!in_array($class, $disallowedElements, true)) {
                    $types[$class] = $this->getElementTypeInfo($class);
                }
            }
        }

        $this->allowedTypesCache[$className] = $types;

        return $types;
    }

    /**
     * Get display metadata for an element class.
     *
     * @param class-string<GridElement> $class
     * @return array{label: string, icon: string, description: string}
     */
    private function getElementTypeInfo(string $class): array
    {
        $config = Config::forClass($class);

        $name = $config->get('singular_name');
        $label = is_string($name) && $name !== '' ? $name : ClassInfo::shortName($class);

        $icon = $config->get('icon');
        $icon = is_string($icon) && $icon !== '' ? $icon : 'font-icon-block-content';

        $description = $config->get('class_description');
        $description = is_string($description) && $description !== '' ? $description : '';

        return [
            'label' => $label,
            'icon' => $icon,
            'description' => $description,
        ];
    }
}
