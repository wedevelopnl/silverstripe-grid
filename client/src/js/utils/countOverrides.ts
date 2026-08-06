import type { ElementNode } from '@/types/elements'
import { isColumnNode, isContainerNode } from '@/types/elements'

export interface OverrideCounts {
  /** Columns carrying an override for any viewport. */
  readonly total: number
  /** Columns carrying an override, per viewport key. */
  readonly byViewport: Record<string, number>
}

/**
 * Count per-viewport grid-settings overrides across a tree.
 *
 * The total is a separate field rather than a sentinel key in `byViewport`,
 * because viewport keys come from the adapter's YAML: a project is free to name
 * a viewport anything, which would collide with a sentinel.
 *
 * An empty tree — or a tree with no overrides — yields `{ total: 0,
 * byViewport: {} }`.
 */
export function countOverrides(nodes: ElementNode[]): OverrideCounts {
  const byViewport: Record<string, number> = {}
  const total = walk(nodes, byViewport)

  return { total, byViewport }
}

function walk(nodes: ElementNode[], byViewport: Record<string, number>): number {
  let total = 0

  for (const node of nodes) {
    if (isColumnNode(node) && Object.keys(node.gridSettings.overrides).length > 0) {
      total++
      for (const viewport of Object.keys(node.gridSettings.overrides)) {
        byViewport[viewport] = (byViewport[viewport] ?? 0) + 1
      }
    }

    if (isContainerNode(node) && node.children !== null) {
      total += walk(node.children, byViewport)
    }
  }

  return total
}
