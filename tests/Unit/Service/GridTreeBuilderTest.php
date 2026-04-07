<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WeDevelop\Grid\Service\GridTreeBuilder;
use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;
use WeDevelop\Grid\Value\GridSettings;
use WeDevelop\Grid\Value\ViewportConfig;

#[CoversClass(GridTreeBuilder::class)]
final class GridTreeBuilderTest extends TestCase
{
    private const array DEFAULT_BLOCK_SCHEMA = [
        'typeName' => 'Test',
        'type' => 'test',
        'title' => 'Test',
        'summary' => '',
        'label' => 'Test',
        'icon' => 'font-icon-block',
    ];

    private function makeNode(array $overrides = []): GridNode
    {
        $defaults = [
            'id' => 1,
            'parentId' => 10,
            'title' => 'Test Node',
            'blockSchema' => self::DEFAULT_BLOCK_SCHEMA,
            'obsoleteClassName' => null,
            'version' => 1,
            'canDelete' => true,
            'canPublish' => true,
            'canUnpublish' => false,
            'canCreate' => true,
            'editLink' => '/admin/edit/1',
            'statusFlags' => [],
            'containerType' => null,
            'allowedTypes' => null,
            'children' => null,
            'gridSettings' => null,
            'extensions' => [],
        ];

        $args = [...$defaults, ...$overrides];

        return new GridNode(
            id: $args['id'],
            parentId: $args['parentId'],
            title: $args['title'],
            blockSchema: $args['blockSchema'],
            obsoleteClassName: $args['obsoleteClassName'],
            version: $args['version'],
            canDelete: $args['canDelete'],
            canPublish: $args['canPublish'],
            canUnpublish: $args['canUnpublish'],
            canCreate: $args['canCreate'],
            editLink: $args['editLink'],
            statusFlags: $args['statusFlags'],
            containerType: $args['containerType'],
            allowedTypes: $args['allowedTypes'],
            children: $args['children'],
            gridSettings: $args['gridSettings'],
            extensions: $args['extensions'],
        );
    }

    // ─── collectContainersOfType ────────────────────────────────

    #[Test]
    public function collectContainersOfTypeReturnsEmptyForEmptyNodes(): void
    {
        self::assertSame([], GridTreeBuilder::collectContainersOfType([], ContainerType::Row));
    }

    #[Test]
    public function collectContainersOfTypeReturnsEmptyWhenNoMatch(): void
    {
        $section = $this->makeNode([
            'id' => 1,
            'title' => 'Section 1',
            'containerType' => ContainerType::Section,
            'children' => [],
        ]);

        self::assertSame([], GridTreeBuilder::collectContainersOfType([$section], ContainerType::Row));
    }

    #[Test]
    public function collectContainersOfTypeFindsMatchingNodes(): void
    {
        $row1 = $this->makeNode([
            'id' => 2,
            'title' => 'Row 1',
            'containerType' => ContainerType::Row,
            'children' => [],
        ]);

        $row2 = $this->makeNode([
            'id' => 3,
            'title' => 'Row 2',
            'containerType' => ContainerType::Row,
            'children' => [],
        ]);

        $section = $this->makeNode([
            'id' => 1,
            'title' => 'Section 1',
            'containerType' => ContainerType::Section,
            'children' => [$row1, $row2],
        ]);

        $result = GridTreeBuilder::collectContainersOfType([$section], ContainerType::Row);

        self::assertCount(2, $result);
        self::assertSame(2, $result[0]['id']);
        self::assertSame('Row 1', $result[0]['title']);
        self::assertSame('row', $result[0]['type']);
        self::assertSame(3, $result[1]['id']);
    }

    #[Test]
    public function collectContainersOfTypeSearchesDeeplyNestedTree(): void
    {
        $column = $this->makeNode([
            'id' => 4,
            'title' => 'Column 1',
            'containerType' => ContainerType::Column,
            'children' => [],
        ]);

        $row = $this->makeNode([
            'id' => 3,
            'containerType' => ContainerType::Row,
            'children' => [$column],
        ]);

        $section = $this->makeNode([
            'id' => 1,
            'containerType' => ContainerType::Section,
            'children' => [$row],
        ]);

        $result = GridTreeBuilder::collectContainersOfType([$section], ContainerType::Column);

        self::assertCount(1, $result);
        self::assertSame(4, $result[0]['id']);
        self::assertSame('column', $result[0]['type']);
    }

    #[Test]
    public function collectContainersOfTypeCollectsAcrossMultipleRootNodes(): void
    {
        $row1 = $this->makeNode(['id' => 2, 'containerType' => ContainerType::Row, 'children' => []]);
        $row2 = $this->makeNode(['id' => 4, 'containerType' => ContainerType::Row, 'children' => []]);
        $section1 = $this->makeNode(['id' => 1, 'containerType' => ContainerType::Section, 'children' => [$row1]]);
        $section2 = $this->makeNode(['id' => 3, 'containerType' => ContainerType::Section, 'children' => [$row2]]);

        $result = GridTreeBuilder::collectContainersOfType([$section1, $section2], ContainerType::Row);

        self::assertCount(2, $result);
        self::assertSame(2, $result[0]['id']);
        self::assertSame(4, $result[1]['id']);
    }

    // ─── countOverrides ─────────────────────────────────────────

    #[Test]
    public function countOverridesReturnsEmptyForEmptyNodes(): void
    {
        self::assertSame([], GridTreeBuilder::countOverrides([]));
    }

    #[Test]
    public function countOverridesReturnsEmptyWhenNoOverridesExist(): void
    {
        $column = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => new GridSettings(ViewportConfig::default(12)),
            'children' => [],
        ]);

        self::assertSame([], GridTreeBuilder::countOverrides([$column]));
    }

    #[Test]
    public function countOverridesCountsSingleViewportOverride(): void
    {
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true)],
        );

        $column = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => $settings,
            'children' => [],
        ]);

        $row = $this->makeNode(['id' => 2, 'containerType' => ContainerType::Row, 'children' => [$column]]);

        $result = GridTreeBuilder::countOverrides([$row]);

        self::assertSame(1, $result['_total']);
        self::assertSame(1, $result['lg']);
    }

    #[Test]
    public function countOverridesCountsMultipleViewportsOnSameColumn(): void
    {
        $settings = new GridSettings(
            ViewportConfig::default(12),
            ['lg' => new ViewportConfig(6, 0, true), 'xl' => new ViewportConfig(4, 0, true)],
        );

        $column = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => $settings,
            'children' => [],
        ]);

        $result = GridTreeBuilder::countOverrides([$column]);

        self::assertSame(1, $result['_total']);
        self::assertSame(1, $result['lg']);
        self::assertSame(1, $result['xl']);
    }

    #[Test]
    public function countOverridesAccumulatesAcrossMultipleColumns(): void
    {
        $col1 = $this->makeNode([
            'id' => 1,
            'containerType' => ContainerType::Column,
            'gridSettings' => new GridSettings(ViewportConfig::default(12), ['lg' => new ViewportConfig(6, 0, true)]),
            'children' => [],
        ]);

        $col2 = $this->makeNode([
            'id' => 2,
            'containerType' => ContainerType::Column,
            'gridSettings' => new GridSettings(ViewportConfig::default(12), ['lg' => new ViewportConfig(4, 0, true), 'sm' => new ViewportConfig(12, 0, false)]),
            'children' => [],
        ]);

        $row = $this->makeNode(['id' => 3, 'containerType' => ContainerType::Row, 'children' => [$col1, $col2]]);

        $result = GridTreeBuilder::countOverrides([$row]);

        self::assertSame(2, $result['_total']);
        self::assertSame(2, $result['lg']);
        self::assertSame(1, $result['sm']);
    }

    #[Test]
    public function countOverridesIgnoresColumnsWithNullGridSettings(): void
    {
        $column = $this->makeNode([
            'containerType' => ContainerType::Column,
            'gridSettings' => null,
            'children' => [],
        ]);

        self::assertSame([], GridTreeBuilder::countOverrides([$column]));
    }

    #[Test]
    public function countOverridesTraversesNestedTree(): void
    {
        $column = $this->makeNode([
            'id' => 4,
            'containerType' => ContainerType::Column,
            'gridSettings' => new GridSettings(ViewportConfig::default(12), ['md' => new ViewportConfig(8, 2, true)]),
            'children' => [],
        ]);

        $row = $this->makeNode(['id' => 3, 'containerType' => ContainerType::Row, 'children' => [$column]]);
        $section = $this->makeNode(['id' => 1, 'containerType' => ContainerType::Section, 'children' => [$row]]);

        $result = GridTreeBuilder::countOverrides([$section]);

        self::assertSame(1, $result['_total']);
        self::assertSame(1, $result['md']);
    }
}
