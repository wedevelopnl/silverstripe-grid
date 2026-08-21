import type { ElementNode, TreeApiResponse } from '@/types/elements'
import { isSectionNode, isSharedBlockReferenceNode } from '@/types/elements'

/**
 * Derive the root-level entries from a tree response, in server order.
 *
 * A zone's roots are Sections AND shared block placements — the server already
 * interleaves them by Sort, so this preserves that order rather than
 * partitioning by type. Returns an empty array when the tree is undefined
 * (query still loading). Pure — callers wrap the result in `useMemo` for
 * referential stability (the list and derived ids feed
 * DndContext/SortableContext props).
 */
export function selectSections(tree: TreeApiResponse | undefined): ElementNode[] {
  return tree === undefined
    ? []
    : tree.nodes.filter((node) => isSectionNode(node) || isSharedBlockReferenceNode(node))
}
