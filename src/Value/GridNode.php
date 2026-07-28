<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use InvalidArgumentException;
use Override;

/**
 * Identity is always scoped by {@see NodeRef} — a pair of {@see NodeType}
 * and positive integer record ID — to prevent collisions between SilverStripe's
 * polymorphic ParentClass namespaces (SiteTree page IDs share the numeric
 * space with GridElement IDs but live in separate tables).
 *
 * Leaf nodes omit container-only fields (containerType, children) from the
 * serialized output; container nodes include both. Allowed child types are NOT
 * carried per node: they depend only on the container TYPE, so
 * {@see GridTree} serializes one map per container type at the tree root
 * instead of repeating identical maps on every container node.
 *
 * @phpstan-import-type SerializedNodeRef from NodeRef
 * @phpstan-type SerializedNode array{self: SerializedNodeRef, parent: SerializedNodeRef, title: non-empty-string, blockSchema: array{typeName: string, type: string, title: string, label: string, icon: string}, obsoleteClassName: string|null, version: int, canDelete: bool, canPublish: bool, canUnpublish: bool, canCreate: bool, editLink: string|null, status: value-of<ElementStatus>, summary?: non-empty-string, containerType?: string, children?: list<mixed>|null, gridSettings?: array{default: array{width: int, offset: int, visible: bool}, overrides: array<non-empty-string, array{width: int, offset: int, visible: bool}>}, extensions?: array<string, mixed>}
 */
final readonly class GridNode implements JsonSerializable
{
    /**
     * @param non-empty-string $title
     * @param array{typeName: string, type: string, title: string, label: string, icon: string} $blockSchema
     * @param list<self>|null $children
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public NodeRef $self,
        public NodeRef $parent,
        public string $title,
        public array $blockSchema,
        public ?string $obsoleteClassName,
        public int $version,
        public bool $canDelete,
        public bool $canPublish,
        public bool $canUnpublish,
        public bool $canCreate,
        public ?string $editLink,
        public ElementStatus $status,
        public ?string $summary = null,
        public ?ContainerType $containerType = null,
        public ?array $children = null,
        public ?GridSettings $gridSettings = null,
        public array $extensions = [],
    ) {
        if ($gridSettings !== null && $containerType !== ContainerType::Column) {
            throw new InvalidArgumentException(
                'gridSettings may only be provided for Column container type',
            );
        }

        if ($containerType === null && $children !== null) {
            throw new InvalidArgumentException(
                'children require a container type',
            );
        }
    }

    /**
     * @return positive-int
     */
    public function getId(): int
    {
        return $this->self->id;
    }

    /**
     * @return positive-int
     */
    public function getParentId(): int
    {
        return $this->parent->id;
    }

    /** @return SerializedNode */
    #[Override]
    public function jsonSerialize(): array
    {
        $data = [
            'self' => $this->self->jsonSerialize(),
            'parent' => $this->parent->jsonSerialize(),
            'title' => $this->title,
            'blockSchema' => $this->blockSchema,
            'obsoleteClassName' => $this->obsoleteClassName,
            'version' => $this->version,
            'canDelete' => $this->canDelete,
            'canPublish' => $this->canPublish,
            'canUnpublish' => $this->canUnpublish,
            'canCreate' => $this->canCreate,
            'editLink' => $this->editLink,
            'status' => $this->status->value,
        ];

        if ($this->summary !== null && $this->summary !== '') {
            $data['summary'] = $this->summary;
        }

        if ($this->containerType instanceof ContainerType) {
            $data['containerType'] = $this->containerType->value;
            /** @var list<mixed>|null $children */
            $children = $this->children;
            $data['children'] = $children;
        }

        if ($this->containerType === ContainerType::Column && $this->gridSettings !== null) {
            $data['gridSettings'] = $this->gridSettings->toArray();
        }

        if ($this->extensions !== []) {
            $data['extensions'] = $this->extensions;
        }

        return $data;
    }
}
