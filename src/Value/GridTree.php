<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;

/**
 * The viewable element tree of one page + zone, as assembled by
 * {@see \WeDevelop\Grid\Service\GridTreeService::buildViewableTree()}.
 *
 * Owns the wire shape of the CMS tree-read endpoints:
 * `{rootParent, allowedTypes, nodes}`. Allowed child types depend only on the
 * container TYPE (the {@see ContainerType} hierarchy rules are hardcoded per
 * type), so they serialize once per type here instead of repeating an
 * identical map on every container node — a page with 20 columns previously
 * shipped 20 copies of the same map.
 *
 * @phpstan-import-type SerializedNodeRef from NodeRef
 * @phpstan-type AllowedTypesByContainerType array<value-of<ContainerType>, array<class-string, array{label: string, icon: string, description: string}>>
 */
final readonly class GridTree implements JsonSerializable
{
    /**
     * @param list<GridNode> $nodes
     * @param AllowedTypesByContainerType $allowedTypes
     */
    public function __construct(
        public NodeRef $rootParent,
        public array $nodes,
        public array $allowedTypes,
    ) {
    }

    /**
     * @return array{rootParent: SerializedNodeRef, allowedTypes: AllowedTypesByContainerType, nodes: list<GridNode>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'rootParent' => $this->rootParent->jsonSerialize(),
            'allowedTypes' => $this->allowedTypes,
            'nodes' => $this->nodes,
        ];
    }
}
