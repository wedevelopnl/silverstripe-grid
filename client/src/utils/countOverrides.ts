import type { ElementNode } from '@/types/elements'
import { isColumnNode, isContainerNode } from '@/types/elements'

/**
 * Count per-viewport grid-settings overrides across a tree.
 *
 * Returns a record keyed by viewport name with the number of columns that
 * have an override for that viewport, plus a `_total` entry counting
 * columns with any override. An empty tree — or a tree with no overrides —
 * yields an empty object.
 */
export function countOverrides(nodes: ElementNode[]): Record<string, number> {
  const counts: Record<string, number> = {}
  walk(nodes, counts)
  return counts
}

function walk(nodes: ElementNode[], counts: Record<string, number>): void {
  for (const node of nodes) {
    if (isColumnNode(node) && Object.keys(node.gridSettings.overrides).length > 0) {
      counts._total = (counts._total ?? 0) + 1
      for (const viewport of Object.keys(node.gridSettings.overrides)) {
        counts[viewport] = (counts[viewport] ?? 0) + 1
      }
    }

    if (isContainerNode(node) && node.children !== null) {
      walk(node.children, counts)
    }
  }
}
