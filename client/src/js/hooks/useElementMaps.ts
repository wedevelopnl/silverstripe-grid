import { useMemo } from 'react'
import type { ElementNode, TreeApiResponse } from '@/types/elements'
import { isContainerNode, isSharedBlockReferenceNode } from '@/types/elements'
import { NodeIdentity, type NodeKey } from '@/types/identity'

export interface ElementMaps {
  /** Every node indexed by its composite {@link NodeKey}. Pages are not stored here. */
  nodeMap: Map<NodeKey, ElementNode>
  /**
   * Children indexed by parent {@link NodeKey}. The root entry is keyed by the
   * tree's `rootParent` (a page), so `childrenByParentKey.get("page-1")` yields
   * the top-level sections. This is the canonical sibling lookup — it is
   * collision-free across the polymorphic parent namespace.
   */
  childrenByParentKey: Map<NodeKey, ElementNode[]>
  /**
   * Position of each node within its parent's children array. Lets reorder,
   * drop-placement and collision code do O(1) sibling-index lookups instead of
   * O(n) `findIndex(...)` scans on every drag-over frame.
   */
  indexByNodeKey: Map<NodeKey, number>
}

function walkNodes(
  nodes: ElementNode[],
  nodeMap: Map<NodeKey, ElementNode>,
  childrenByParentKey: Map<NodeKey, ElementNode[]>,
  indexByNodeKey: Map<NodeKey, number>,
): void {
  for (let i = 0; i < nodes.length; i++) {
    const node = nodes[i]
    nodeMap.set(node.nodeKey, node)
    indexByNodeKey.set(node.nodeKey, i)

    // A shared block placement holds children without being a container — its
    // single child is the block's root. Indexing it (and everything below)
    // is what lets drags inside a placed block resolve at all.
    const children =
      isContainerNode(node) || isSharedBlockReferenceNode(node) ? node.children : null

    if (children) {
      childrenByParentKey.set(node.nodeKey, children)
      walkNodes(children, nodeMap, childrenByParentKey, indexByNodeKey)
    }
  }
}

/**
 * Build flat lookup maps from a structured tree response.
 *
 * - `nodeMap`: every non-page node by {@link NodeKey} for O(1) lookup.
 * - `childrenByParentKey`: parent key → children array for O(1) sibling lookup.
 *   The root entry (page → sections) is keyed by the page's NodeKey.
 * - `indexByNodeKey`: node key → position within its parent's children array.
 */
export function buildMaps(tree: TreeApiResponse): ElementMaps {
  const nodeMap = new Map<NodeKey, ElementNode>()
  const childrenByParentKey = new Map<NodeKey, ElementNode[]>()
  const indexByNodeKey = new Map<NodeKey, number>()

  const rootKey = NodeIdentity.toKey(tree.rootParent)
  childrenByParentKey.set(rootKey, tree.nodes)
  walkNodes(tree.nodes, nodeMap, childrenByParentKey, indexByNodeKey)

  return { nodeMap, childrenByParentKey, indexByNodeKey }
}

/**
 * React hook that memoizes element lookup maps from a tree response.
 */
export function useElementMaps(tree: TreeApiResponse): ElementMaps {
  return useMemo(() => buildMaps(tree), [tree])
}
