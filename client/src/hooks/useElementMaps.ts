import { useMemo } from 'react';
import { isContainerNode } from '@/types/elements';
import type { ElementNode, TreeApiResponse } from '@/types/elements';
import { NodeIdentity, type NodeKey } from '@/types/identity';

export interface ElementMaps {
  /** Every node indexed by its composite {@link NodeKey}. Pages are not stored here. */
  nodeMap: Map<NodeKey, ElementNode>;
  /**
   * Children indexed by parent {@link NodeKey}. The root entry is keyed by the
   * tree's `rootParent` (a page), so `childrenByParentKey.get("page-1")` yields
   * the top-level sections. This is the canonical sibling lookup — it is
   * collision-free across the polymorphic parent namespace.
   */
  childrenByParentKey: Map<NodeKey, ElementNode[]>;
}

function walkNodes(
  nodes: ElementNode[],
  nodeMap: Map<NodeKey, ElementNode>,
  childrenByParentKey: Map<NodeKey, ElementNode[]>,
): void {
  for (const node of nodes) {
    nodeMap.set(node.nodeKey, node);

    if (isContainerNode(node) && node.children) {
      childrenByParentKey.set(node.nodeKey, node.children);
      walkNodes(node.children, nodeMap, childrenByParentKey);
    }
  }
}

/**
 * Build flat lookup maps from a structured tree response.
 *
 * - `nodeMap`: every non-page node by {@link NodeKey} for O(1) lookup.
 * - `childrenByParentKey`: parent key → children array for O(1) sibling lookup.
 *   The root entry (page → sections) is keyed by the page's NodeKey.
 */
export function buildMaps(tree: TreeApiResponse): ElementMaps {
  const nodeMap = new Map<NodeKey, ElementNode>();
  const childrenByParentKey = new Map<NodeKey, ElementNode[]>();

  const rootKey = NodeIdentity.toKey(tree.rootParent);
  childrenByParentKey.set(rootKey, tree.nodes);
  walkNodes(tree.nodes, nodeMap, childrenByParentKey);

  return { nodeMap, childrenByParentKey };
}

/**
 * React hook that memoizes element lookup maps from a tree response.
 */
export function useElementMaps(tree: TreeApiResponse): ElementMaps {
  return useMemo(() => buildMaps(tree), [tree]);
}
