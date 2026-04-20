<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\ElementStatus;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\NodeRef;
use WeDevelop\Grid\Value\NodeType;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridNode::class)]
final class GridNodeTest extends TestCase
{
    private const array DEFAULT_BLOCK_SCHEMA = [
        'typeName' => 'Test',
        'type' => 'test',
        'title' => 'Test',

        'label' => 'Test',
        'icon' => 'font-icon-block',
    ];

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeNode(array $overrides = []): GridNode
    {
        $defaults = [
            'self' => new NodeRef(NodeType::Section, 1),
            'parent' => new NodeRef(NodeType::Page, 10),
            'title' => 'Test Node',
            'blockSchema' => self::DEFAULT_BLOCK_SCHEMA,
            'obsoleteClassName' => null,
            'version' => 1,
            'canDelete' => true,
            'canPublish' => true,
            'canUnpublish' => false,
            'canCreate' => true,
            'editLink' => '/admin/edit/1',
            'status' => ElementStatus::Published,
            'containerType' => null,
            'allowedTypes' => null,
            'children' => null,
            'gridSettings' => null,
            'extensions' => [],
        ];

        $args = [...$defaults, ...$overrides];

        return new GridNode(
            self: $args['self'],
            parent: $args['parent'],
            title: $args['title'],
            blockSchema: $args['blockSchema'],
            obsoleteClassName: $args['obsoleteClassName'],
            version: $args['version'],
            canDelete: $args['canDelete'],
            canPublish: $args['canPublish'],
            canUnpublish: $args['canUnpublish'],
            canCreate: $args['canCreate'],
            editLink: $args['editLink'],
            status: $args['status'],
            containerType: $args['containerType'],
            allowedTypes: $args['allowedTypes'],
            children: $args['children'],
            gridSettings: $args['gridSettings'],
            extensions: $args['extensions'],
        );
    }

    #[Test]
    public function getIdAndGetParentIdReturnNumericIdsFromRefs(): void
    {
        $node = $this->makeNode([
            'self' => new NodeRef(NodeType::Row, 42),
            'parent' => new NodeRef(NodeType::Section, 7),
        ]);

        self::assertSame(42, $node->getId());
        self::assertSame(7, $node->getParentId());
    }

    #[Test]
    public function constructorThrowsWhenGridSettingsProvidedForSection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gridSettings may only be provided for Column container type');

        $this->makeNode([
            'containerType' => ContainerType::Section,
            'gridSettings' => new GridSettings(ViewportConfig::default(12)),
        ]);
    }

    #[Test]
    public function constructorAllowsGridSettingsOnColumn(): void
    {
        $gridSettings = new GridSettings(ViewportConfig::default(12));

        $node = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => $gridSettings,
        ]);

        self::assertSame($gridSettings, $node->gridSettings);
    }

    #[Test]
    public function constructorAllowsNullGridSettingsOnColumn(): void
    {
        $node = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => null,
        ]);

        self::assertNull($node->gridSettings);
    }

    #[Test]
    public function jsonSerializeLeafIncludesScopedIdentity(): void
    {
        $node = $this->makeNode([
            'self' => new NodeRef(NodeType::Element, 1),
            'parent' => new NodeRef(NodeType::Column, 10),
            'containerType' => null,
        ]);
        $data = $node->jsonSerialize();

        self::assertSame(['type' => 'element', 'id' => 1], $data['self']);
        self::assertSame(['type' => 'column', 'id' => 10], $data['parent']);
        self::assertSame('Test Node', $data['title']);
        self::assertSame(self::DEFAULT_BLOCK_SCHEMA, $data['blockSchema']);
        self::assertNull($data['obsoleteClassName']);
        self::assertSame(1, $data['version']);
        self::assertTrue($data['canDelete']);
        self::assertTrue($data['canPublish']);
        self::assertFalse($data['canUnpublish']);
        self::assertTrue($data['canCreate']);
        self::assertSame('/admin/edit/1', $data['editLink']);
        self::assertSame('published', $data['status']);

        self::assertArrayNotHasKey('containerType', $data);
        self::assertArrayNotHasKey('allowedTypes', $data);
        self::assertArrayNotHasKey('children', $data);
    }

    #[Test]
    public function jsonSerializeContainerIncludesContainerFields(): void
    {
        $allowedTypes = ['SomeClass' => ['label' => 'Row', 'icon' => 'icon', 'description' => 'desc']];

        $node = $this->makeNode([
            'containerType' => ContainerType::Section,
            'allowedTypes' => $allowedTypes,
            'children' => [],
        ]);

        $data = $node->jsonSerialize();

        self::assertSame('section', $data['containerType']);
        self::assertSame($allowedTypes, $data['allowedTypes']);
        self::assertSame([], $data['children']);
    }

    #[Test]
    public function jsonSerializeColumnWithGridSettingsIncludesGridSettingsKey(): void
    {
        $gridSettings = new GridSettings(ViewportConfig::default(12));

        $node = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => $gridSettings,
        ]);

        $data = $node->jsonSerialize();

        self::assertArrayHasKey('gridSettings', $data);
        self::assertSame($gridSettings->toArray(), $data['gridSettings']);
    }

    #[Test]
    public function jsonSerializeColumnWithNullGridSettingsOmitsGridSettingsKey(): void
    {
        $node = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => null,
        ]);

        $data = $node->jsonSerialize();

        self::assertArrayNotHasKey('gridSettings', $data);
    }

    #[Test]
    public function jsonSerializeIncludesExtensionsWhenNonEmpty(): void
    {
        $extensions = ['mediaLayout' => ['aspectRatio' => '16x9']];

        $node = $this->makeNode(['extensions' => $extensions]);
        $data = $node->jsonSerialize();

        self::assertArrayHasKey('extensions', $data);
        self::assertSame($extensions, $data['extensions']);
    }

    #[Test]
    public function jsonSerializeOmitsExtensionsWhenEmpty(): void
    {
        $node = $this->makeNode(['extensions' => []]);
        $data = $node->jsonSerialize();

        self::assertArrayNotHasKey('extensions', $data);
    }

    #[Test]
    public function jsonSerializeIncludesStatusAsEnumValue(): void
    {
        $node = $this->makeNode(['status' => ElementStatus::Modified]);
        $data = $node->jsonSerialize();

        self::assertSame('modified', $data['status']);
    }
}
