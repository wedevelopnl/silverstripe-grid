<?php

declare(strict_types=1);

namespace WeDevelop\Grid\Service;

use WeDevelop\Grid\Value\ContainerType;
use WeDevelop\Grid\Value\GridNode;

/**
 * Pure traversal helpers over an already-built {@see GridNode} tree.
 *
 * Read-only: operates on the DTO shape produced by {@see GridTreeBuilder}.
 * No DI, no state — every method is stateless and side-effect-free.
 */
final class GridTreeWalker
{
    /**
     * Recursively collect containers matching the target type from a pre-built tree.
     *
     * @param list<GridNode> $nodes
     * @return list<array{id: positive-int, title: string, type: string}>
     */
    public static function collectContainersOfType(array $nodes, ContainerType $targetType): array
    {
        /** @var list<array{id: positive-int, title: string, type: string}> $containers */
        $containers = [];
        self::doCollectContainers($nodes, $targetType, $containers);

        return $containers;
    }

    /**
     * Walk a pre-built tree and count how many columns have overrides per viewport,
     * plus a total count of columns with any overrides.
     *
     * @param list<GridNode> $nodes
     * @return array<non-empty-string, int>
     */
    public static function countOverrides(array $nodes): array
    {
        /** @var array<non-empty-string, int> $counts */
        $counts = [];
        self::doCountOverrides($nodes, $counts);

        return $counts;
    }

    /**
     * @param list<GridNode> $nodes
     * @param list<array{id: positive-int, title: string, type: string}> $containers
     */
    private static function doCollectContainers(array $nodes, ContainerType $targetType, array &$containers): void
    {
        foreach ($nodes as $node) {
            if ($node->containerType === $targetType) {
                $containers[] = [
                    'id' => $node->getId(),
                    'title' => $node->title,
                    'type' => $targetType->value,
                ];
            }

            if ($node->children !== null) {
                self::doCollectContainers($node->children, $targetType, $containers);
            }
        }
    }

    /**
     * @param list<GridNode> $nodes
     * @param array<non-empty-string, int> $counts
     */
    private static function doCountOverrides(array $nodes, array &$counts): void
    {
        foreach ($nodes as $node) {
            if ($node->gridSettings !== null && $node->gridSettings->overrides !== []) {
                $counts['_total'] = ($counts['_total'] ?? 0) + 1;

                foreach (array_keys($node->gridSettings->overrides) as $viewport) {
                    $counts[$viewport] = ($counts[$viewport] ?? 0) + 1;
                }
            }

            if ($node->children !== null) {
                self::doCountOverrides($node->children, $counts);
            }
        }
    }
}
