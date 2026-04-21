<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

/**
 * Maps GridElement models to GridNode DTOs.
 *
 * Handles title fallback, blockSchema assembly, icon resolution,
 * permission checks, allowed child type enumeration, and the
 * updateElementData extension hook.
 */
class GridNodeMapper
{
    use Extensible;
    use Injectable;

    /** @var array<class-string, array<class-string, array{label: string, icon: string, description: string}>> */
    private array $allowedTypesCache = [];

    /**
     * Map a GridElement to a GridNode DTO.
     *
     * @param array<class-string, array{label: string, icon: string, description: string}>|null $allowedTypes
     * @param list<GridNode>|null $children
     */
    public function mapToNode(
        GridElement $element,
        NodeRef $parent,
        ?ContainerType $containerType,
        ?array $allowedTypes,
        ?array $children,
        ?GridSettings $gridSettings,
    ): GridNode {
        /** @var non-empty-string $title Fallback '(untitled)' guarantees non-empty */
        $title = $element->Title ?: _t(
            GridElement::class . '.UNTITLED',
            '(untitled)',
        );

        /** @var array{typeName: string, type: string, title: string, label: string} $blockSchema */
        $blockSchema = $element->getBlockSchema();
        $blockSchema['label'] = $element->getType();

        $icon = Config::forClass($element::class)->get('icon');

        /** @var array{typeName: string, type: string, title: string, label: string, icon: string} $blockSchemaWithIcon */
        $blockSchemaWithIcon = array_merge($blockSchema, [
            'icon' => is_string($icon) && $icon !== '' ? $icon : 'font-icon-block-content',
        ]);

        /** @var array<string, array{text: string, title: string}> $statusFlags */
        $statusFlags = $element->getStatusFlags();
        $status = ElementStatus::fromStatusFlags($statusFlags);

        $summary = $element->getSummary();

        /** @var array<string, mixed> $extensions */
        $extensions = [];
        $this->extend('updateElementData', $element, $extensions);
        assert($element instanceof GridElement); // extend() passes by-ref, widening the type
        /** @var array<string, mixed> $extensions PHPStan: extend() widens by-ref params */

        /** @var positive-int $id */
        $id = (int) $element->ID;

        $canUnpublish = $element->canUnpublish();
        assert(is_bool($canUnpublish));

        $self = new NodeRef(NodeType::fromClass($element::class), $id);

        return new GridNode(
            self: $self,
            parent: $parent,
            title: $title,
            blockSchema: $blockSchemaWithIcon,
            obsoleteClassName: $element->getObsoleteClassName(),
            version: (int) $element->Version,
            canDelete: $element->canDelete(),
            canPublish: $element->canPublish(),
            canUnpublish: $canUnpublish,
            canCreate: $element->canCreate(),
            editLink: $element->getCMSEditLink(),
            status: $status,
            summary: $summary,
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
     * @param ContainerInterface<GridElement> $container
     * @return array<class-string, array{label: string, icon: string, description: string}>
     */
    public function getAllowedTypes(ContainerInterface $container): array
    {
        $className = $container::class;

        if (isset($this->allowedTypesCache[$className])) {
            return $this->allowedTypesCache[$className];
        }

        $containerType = $container->getContainerType();
        $allowedChild = $containerType->allowedChildClass();

        $types = [];

        if ($allowedChild !== null) {
            // Section/Row: single allowed child class (+ subclasses)
            foreach (ClassInfo::subclassesFor($allowedChild, true) as $class) {
                /** @var class-string<GridElement> $class */
                $types[$class] = $this->getElementTypeInfo($class);
            }
        } else {
            // Column: any GridElement except containers
            foreach (ClassInfo::subclassesFor(GridElement::class, false) as $class) {
                /** @var class-string<GridElement> $class */
                if ($containerType->isChildAllowed($class)) {
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
