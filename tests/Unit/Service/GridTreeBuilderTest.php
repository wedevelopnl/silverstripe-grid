<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private static function makeNode(array $overrides = []): GridNode
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

    /** @return iterable<string, array{list<GridNode>, ContainerType, list<array{id: int, title: string, type: string}>}> */
    public static function collectContainersOfTypeProvider(): iterable
    {
        yield 'empty nodes' => [
            [],
            ContainerType::Row,
            [],
        ];

        yield 'no matching type' => [
            [self::makeNode(['id' => 1, 'containerType' => ContainerType::Section, 'children' => []])],
            ContainerType::Row,
            [],
        ];

        yield 'leaf node without container type' => [
            [self::makeNode(['id' => 1])],
            ContainerType::Row,
            [],
        ];

        yield 'match at root level' => [
            [
                self::makeNode(['id' => 1, 'title' => 'Section 1', 'containerType' => ContainerType::Section, 'children' => []]),
                self::makeNode(['id' => 2, 'title' => 'Section 2', 'containerType' => ContainerType::Section, 'children' => []]),
            ],
            ContainerType::Section,
            [
                ['id' => 1, 'title' => 'Section 1', 'type' => 'section'],
                ['id' => 2, 'title' => 'Section 2', 'type' => 'section'],
            ],
        ];

        yield 'matches at child level' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode(['id' => 2, 'title' => 'Row 1', 'containerType' => ContainerType::Row, 'children' => []]),
                        self::makeNode(['id' => 3, 'title' => 'Row 2', 'containerType' => ContainerType::Row, 'children' => []]),
                    ],
                ]),
            ],
            ContainerType::Row,
            [
                ['id' => 2, 'title' => 'Row 1', 'type' => 'row'],
                ['id' => 3, 'title' => 'Row 2', 'type' => 'row'],
            ],
        ];

        yield 'deeply nested match' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode([
                            'id' => 3,
                            'containerType' => ContainerType::Row,
                            'children' => [
                                self::makeNode(['id' => 4, 'title' => 'Column 1', 'containerType' => ContainerType::Column, 'children' => []]),
                            ],
                        ]),
                    ],
                ]),
            ],
            ContainerType::Column,
            [
                ['id' => 4, 'title' => 'Column 1', 'type' => 'column'],
            ],
        ];

        yield 'multiple root nodes' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode(['id' => 2, 'containerType' => ContainerType::Row, 'children' => []]),
                    ],
                ]),
                self::makeNode([
                    'id' => 3,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode(['id' => 4, 'containerType' => ContainerType::Row, 'children' => []]),
                    ],
                ]),
            ],
            ContainerType::Row,
            [
                ['id' => 2, 'title' => 'Test Node', 'type' => 'row'],
                ['id' => 4, 'title' => 'Test Node', 'type' => 'row'],
            ],
        ];

        // Matching node should still recurse into its children
        yield 'match and recurse into children' => [
            [
                self::makeNode([
                    'id' => 1,
                    'title' => 'Row 1',
                    'containerType' => ContainerType::Row,
                    'children' => [
                        self::makeNode([
                            'id' => 2,
                            'title' => 'Column 1',
                            'containerType' => ContainerType::Column,
                            'children' => [],
                        ]),
                    ],
                ]),
            ],
            ContainerType::Column,
            [
                ['id' => 2, 'title' => 'Column 1', 'type' => 'column'],
            ],
        ];

        yield 'mixed types at same level' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Row,
                    'children' => [
                        self::makeNode(['id' => 2, 'title' => 'Column 1', 'containerType' => ContainerType::Column, 'children' => []]),
                        self::makeNode(['id' => 3, 'title' => 'Content']),
                        self::makeNode(['id' => 4, 'title' => 'Column 2', 'containerType' => ContainerType::Column, 'children' => []]),
                    ],
                ]),
            ],
            ContainerType::Column,
            [
                ['id' => 2, 'title' => 'Column 1', 'type' => 'column'],
                ['id' => 4, 'title' => 'Column 2', 'type' => 'column'],
            ],
        ];
    }

    /** @param list<GridNode> $nodes */
    /** @param list<array{id: int, title: string, type: string}> $expected */
    #[DataProvider('collectContainersOfTypeProvider')]
    public function testCollectContainersOfType(array $nodes, ContainerType $targetType, array $expected): void
    {
        $result = GridTreeBuilder::collectContainersOfType($nodes, $targetType);

        self::assertCount(count($expected), $result);

        foreach ($expected as $i => $entry) {
            self::assertSame($entry['id'], $result[$i]['id']);
            self::assertSame($entry['title'], $result[$i]['title']);
            self::assertSame($entry['type'], $result[$i]['type']);
        }
    }

    // ─── countOverrides ─────────────────────────────────────────

    /** @return iterable<string, array{list<GridNode>, array<string, int>}> */
    public static function countOverridesProvider(): iterable
    {
        yield 'empty nodes' => [
            [],
            [],
        ];

        yield 'no overrides exist' => [
            [
                self::makeNode([
                    'containerType' => ContainerType::Column,
                    'gridSettings' => new GridSettings(ViewportConfig::default(12)),
                    'children' => [],
                ]),
            ],
            [],
        ];

        yield 'gridSettings with explicitly empty overrides' => [
            [
                self::makeNode([
                    'containerType' => ContainerType::Column,
                    'gridSettings' => new GridSettings(ViewportConfig::default(12), []),
                    'children' => [],
                ]),
            ],
            [],
        ];

        yield 'null grid settings' => [
            [
                self::makeNode([
                    'containerType' => ContainerType::Column,
                    'gridSettings' => null,
                    'children' => [],
                ]),
            ],
            [],
        ];

        yield 'non-column node without grid settings' => [
            [
                self::makeNode([
                    'containerType' => ContainerType::Row,
                    'children' => [
                        self::makeNode([
                            'containerType' => ContainerType::Column,
                            'gridSettings' => null,
                            'children' => [],
                        ]),
                    ],
                ]),
            ],
            [],
        ];

        yield 'single viewport override' => [
            [
                self::makeNode([
                    'id' => 2,
                    'containerType' => ContainerType::Row,
                    'children' => [
                        self::makeNode([
                            'containerType' => ContainerType::Column,
                            'gridSettings' => new GridSettings(
                                ViewportConfig::default(12),
                                ['lg' => new ViewportConfig(6, 0, true)],
                            ),
                            'children' => [],
                        ]),
                    ],
                ]),
            ],
            ['_total' => 1, 'lg' => 1],
        ];

        yield 'multiple viewports on same column' => [
            [
                self::makeNode([
                    'containerType' => ContainerType::Column,
                    'gridSettings' => new GridSettings(
                        ViewportConfig::default(12),
                        ['lg' => new ViewportConfig(6, 0, true), 'xl' => new ViewportConfig(4, 0, true)],
                    ),
                    'children' => [],
                ]),
            ],
            ['_total' => 1, 'lg' => 1, 'xl' => 1],
        ];

        yield 'accumulates across multiple columns' => [
            [
                self::makeNode([
                    'id' => 3,
                    'containerType' => ContainerType::Row,
                    'children' => [
                        self::makeNode([
                            'id' => 1,
                            'containerType' => ContainerType::Column,
                            'gridSettings' => new GridSettings(
                                ViewportConfig::default(12),
                                ['lg' => new ViewportConfig(6, 0, true)],
                            ),
                            'children' => [],
                        ]),
                        self::makeNode([
                            'id' => 2,
                            'containerType' => ContainerType::Column,
                            'gridSettings' => new GridSettings(
                                ViewportConfig::default(12),
                                ['lg' => new ViewportConfig(4, 0, true), 'sm' => new ViewportConfig(12, 0, false)],
                            ),
                            'children' => [],
                        ]),
                    ],
                ]),
            ],
            ['_total' => 2, 'lg' => 2, 'sm' => 1],
        ];

        yield 'nested tree traversal' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode([
                            'id' => 3,
                            'containerType' => ContainerType::Row,
                            'children' => [
                                self::makeNode([
                                    'id' => 4,
                                    'containerType' => ContainerType::Column,
                                    'gridSettings' => new GridSettings(
                                        ViewportConfig::default(12),
                                        ['md' => new ViewportConfig(8, 2, true)],
                                    ),
                                    'children' => [],
                                ]),
                            ],
                        ]),
                    ],
                ]),
            ],
            ['_total' => 1, 'md' => 1],
        ];

        yield 'columns at different depths accumulate' => [
            [
                self::makeNode([
                    'id' => 1,
                    'containerType' => ContainerType::Section,
                    'children' => [
                        self::makeNode([
                            'id' => 2,
                            'containerType' => ContainerType::Row,
                            'children' => [
                                self::makeNode([
                                    'id' => 3,
                                    'containerType' => ContainerType::Column,
                                    'gridSettings' => new GridSettings(
                                        ViewportConfig::default(12),
                                        ['lg' => new ViewportConfig(6, 0, true)],
                                    ),
                                    'children' => [],
                                ]),
                            ],
                        ]),
                    ],
                ]),
                self::makeNode([
                    'id' => 5,
                    'containerType' => ContainerType::Column,
                    'gridSettings' => new GridSettings(
                        ViewportConfig::default(12),
                        ['lg' => new ViewportConfig(4, 0, true), 'xl' => new ViewportConfig(3, 0, true)],
                    ),
                    'children' => [],
                ]),
            ],
            ['_total' => 2, 'lg' => 2, 'xl' => 1],
        ];
    }

    /** @param list<GridNode> $nodes */
    /** @param array<string, int> $expected */
    #[DataProvider('countOverridesProvider')]
    public function testCountOverrides(array $nodes, array $expected): void
    {
        self::assertSame($expected, GridTreeBuilder::countOverrides($nodes));
    }
}
