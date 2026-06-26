<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Value;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'summary' => null,
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
            summary: $args['summary'],
            containerType: $args['containerType'],
            allowedTypes: $args['allowedTypes'],
            children: $args['children'],
            gridSettings: $args['gridSettings'],
            extensions: $args['extensions'],
        );
    }

    public function testGetIdAndGetParentIdReturnNumericIdsFromRefs(): void
    {
        $node = $this->makeNode([
            'self' => new NodeRef(NodeType::Row, 42),
            'parent' => new NodeRef(NodeType::Section, 7),
        ]);

        self::assertSame(42, $node->getId());
        self::assertSame(7, $node->getParentId());
    }

    public function testConstructorThrowsWhenGridSettingsProvidedForSection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('gridSettings may only be provided for Column container type');

        $this->makeNode([
            'containerType' => ContainerType::Section,
            'gridSettings' => new GridSettings(ViewportConfig::default(12)),
        ]);
    }

    public function testConstructorThrowsWhenLeafCarriesChildren(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('children and allowedTypes require a container type');

        // containerType null (leaf) but children provided — inconsistent.
        $this->makeNode([
            'containerType' => null,
            'children' => [],
        ]);
    }

    public function testConstructorThrowsWhenLeafCarriesAllowedTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('children and allowedTypes require a container type');

        $this->makeNode([
            'containerType' => null,
            'allowedTypes' => ['SomeClass' => ['label' => 'X', 'icon' => 'i', 'description' => 'd']],
        ]);
    }

    public function testJsonSerializeLeafIncludesScopedIdentity(): void
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

    public function testJsonSerializeContainerIncludesContainerFields(): void
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

    public function testJsonSerializeColumnWithGridSettingsIncludesGridSettingsKey(): void
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

    public function testJsonSerializeColumnWithNullGridSettingsOmitsGridSettingsKey(): void
    {
        $node = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => null,
        ]);

        $data = $node->jsonSerialize();

        self::assertArrayNotHasKey('gridSettings', $data);
    }

    public function testJsonSerializeIncludesExtensionsWhenNonEmpty(): void
    {
        $extensions = ['mediaLayout' => ['aspectRatio' => '16x9']];

        $node = $this->makeNode(['extensions' => $extensions]);
        $data = $node->jsonSerialize();

        self::assertArrayHasKey('extensions', $data);
        self::assertSame($extensions, $data['extensions']);
    }

    public function testJsonSerializeOmitsExtensionsWhenEmpty(): void
    {
        $node = $this->makeNode(['extensions' => []]);
        $data = $node->jsonSerialize();

        self::assertArrayNotHasKey('extensions', $data);
    }

    /**
     * Pins the `summary !== null && summary !== ''` guard in jsonSerialize:
     * a non-empty summary surfaces under the `summary` key with its value,
     * while both null and empty-string omit the key entirely.
     *
     * @param non-empty-string|null $summary
     */
    #[DataProvider('summarySerializationProvider')]
    public function testJsonSerializeIncludesSummaryOnlyWhenNonEmpty(
        ?string $summary,
        bool $expectKey,
    ): void {
        $node = $this->makeNode(['summary' => $summary]);

        $data = $node->jsonSerialize();

        if ($expectKey) {
            self::assertArrayHasKey('summary', $data);
            self::assertSame($summary, $data['summary']);
        } else {
            self::assertArrayNotHasKey('summary', $data);
        }
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function summarySerializationProvider(): iterable
    {
        yield 'non-empty summary present' => ['A summary', true];
        yield 'null summary omitted' => [null, false];
        yield 'empty-string summary omitted' => ['', false];
    }

    public function testJsonSerializeIncludesStatusAsEnumValue(): void
    {
        $node = $this->makeNode(['status' => ElementStatus::Modified]);
        $data = $node->jsonSerialize();

        self::assertSame('modified', $data['status']);
    }
}
