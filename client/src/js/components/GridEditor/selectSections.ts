import type { SectionNode, TreeApiResponse } from '@/types/elements'
import { isSectionNode } from '@/types/elements'

/**
 * Derive the section nodes from a tree response. Returns an empty array when
 * the tree is undefined (query still loading). Pure — callers wrap the result
 * in `useMemo` for referential stability (the section list and derived
 * `sectionIds` feed DndContext/SortableContext props).
 */
export function selectSections(tree: TreeApiResponse | undefined): SectionNode[] {
  return tree === undefined ? [] : tree.nodes.filter(isSectionNode)
}
