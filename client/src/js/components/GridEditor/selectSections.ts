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
  if (tree === undefined) {
    return []
  }

  // A block roots ONE subtree of any shape — a Row, a Column or a lone element
  // just as legitimately as a Section — so the library editor's root list is
  // whatever the server sent. Filtering it by shape rendered an empty editor
  // for every block that was not section-rooted.
  if (tree.rootParent.type === 'sharedBlock') {
    return tree.nodes
  }

  return tree.nodes.filter((node) => isSectionNode(node) || isSharedBlockReferenceNode(node))
}
