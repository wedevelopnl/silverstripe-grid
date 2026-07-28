<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use WeDevelop\Grid\Contract\ContainerInterface;
use WeDevelop\Grid\Model\Column;
use WeDevelop\Grid\Model\GridElement;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

/**
 * Maps GridElement models to GridNode DTOs.
 *
 * Derives all node content from the element itself: containerType,
 * gridSettings, blockSchema assembly, icon resolution, permission checks,
 * and the updateElementData extension hook. The title comes from
 * GridElement::getDisplayTitle(). Allowed child types are NOT per-node data —
 * they depend only on the container type and are exposed once per type via
 * {@see allowedTypesByContainerType()} for the tree root.
 */
class GridNodeMapper
{
    use Extensible;
    use Injectable;

    /** @var array<value-of<ContainerType>, array<class-string<GridElement>, array{label: string, icon: string, description: string}>> */
    private array $allowedTypesCache = [];

    /**
     * @param list<GridNode>|null $children Assembled child nodes — null for leaf elements.
     *   The only structural fact the traversal must supply besides $parent; all
     *   node content (containerType, allowedTypes, gridSettings) is derived here.
     */
    public function mapToNode(
        GridElement $element,
        NodeRef $parent,
        ?array $children,
    ): GridNode {
        $containerType = null;
        $gridSettings = null;

        if ($element instanceof ContainerInterface) {
            $containerType = $element->getContainerType();
        }

        if ($element instanceof Column) {
            $gridSettings = $element->getGridSettings();
        }

        $title = $element->getDisplayTitle();

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
            children: $children,
            gridSettings: $gridSettings,
            extensions: $extensions,
        );
    }

    /**
     * Allowed child element types for every container type, keyed by the
     * ContainerType enum value. Serialized once at the tree root — the rules
     * are hardcoded per container TYPE, so per-node maps would be identical
     * copies for every container of the same type.
     *
     * @return array<value-of<ContainerType>, array<class-string<GridElement>, array{label: string, icon: string, description: string}>>
     */
    public function allowedTypesByContainerType(): array
    {
        $result = [];
        foreach (ContainerType::cases() as $containerType) {
            $result[$containerType->value] = $this->getAllowedTypes($containerType);
        }

        return $result;
    }

    /**
     * Get allowed child element types for a container type, cached per type.
     *
     * @return array<class-string<GridElement>, array{label: string, icon: string, description: string}>
     */
    private function getAllowedTypes(ContainerType $containerType): array
    {
        if (isset($this->allowedTypesCache[$containerType->value])) {
            return $this->allowedTypesCache[$containerType->value];
        }

        $types = [];

        // ContainerType::isChildAllowed() encodes the full hierarchy rule for
        // every container type (Section/Row: the allowed child class + subclasses;
        // Column: any non-container GridElement), so a single filtered pass over
        // all GridElement subclasses covers all cases.
        foreach (ClassInfo::subclassesFor(GridElement::class, false) as $class) {
            /** @var class-string<GridElement> $class */
            if ($containerType->isChildAllowed($class)) {
                $types[$class] = $this->getElementTypeInfo($class);
            }
        }

        $this->allowedTypesCache[$containerType->value] = $types;

        return $types;
    }

    /**
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
