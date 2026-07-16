<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\GridTree;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;

#[CoversClass(GridTree::class)]
final class GridTreeTest extends TestCase
{
    public function testJsonSerializeEmitsWireShapeWithSerializedRootParentAndNodesPassthrough(): void
    {
        $tree = new GridTree(new NodeRef(NodeType::Page, 7), []);

        self::assertSame(
            ['rootParent' => ['type' => 'page', 'id' => 7], 'nodes' => []],
            $tree->jsonSerialize(),
        );
    }
}
