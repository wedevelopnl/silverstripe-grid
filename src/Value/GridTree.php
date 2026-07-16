<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Value;

use JsonSerializable;
use Override;

/**
 * The viewable element tree of one page + zone, as assembled by
 * {@see \WeDevelop\Grid\Service\GridTreeService::buildViewableTree()}.
 *
 * Owns the wire shape of the CMS tree-read endpoints: `{rootParent, nodes}`.
 *
 * @phpstan-import-type SerializedNodeRef from NodeRef
 */
final readonly class GridTree implements JsonSerializable
{
    /**
     * @param list<GridNode> $nodes
     */
    public function __construct(
        public NodeRef $rootParent,
        public array $nodes,
    ) {
    }

    /**
     * @return array{rootParent: SerializedNodeRef, nodes: list<GridNode>}
     */
    #[Override]
    public function jsonSerialize(): array
    {
        return [
            'rootParent' => $this->rootParent->jsonSerialize(),
            'nodes' => $this->nodes,
        ];
    }
}
