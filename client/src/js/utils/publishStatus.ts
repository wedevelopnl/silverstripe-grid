import { type ElementNode, isContainerNode } from '@/types/elements'
import type { ElementStatus } from '@/types/status'

/** Statuses that mean "this differs from what is live". */
const UNPUBLISHED: readonly ElementStatus[] = ['draft', 'modified']

/**
 * Whether this element itself has work that has never reached the live stage —
 * either never published (`draft`) or published and since changed (`modified`).
 *
 * `removed` is excluded on purpose: it means live-only, and such elements are
 * not rendered in the editor tree, so a mark for them could never be seen.
 */
export function isUnpublished(status: ElementStatus): boolean {
  return UNPUBLISHED.includes(status)
}

/**
 * Whether anything *below* `node` is unpublished.
 *
 * The editor draws two marks: an element with its own unpublished work gets a
 * "Draft"/"Modified" pill, while an element that merely contains such work gets
 * the dot. Without this walk the second mark is impossible — an unpublished
 * block inside a collapsed section would be invisible until the editor expanded
 * it, which is exactly the state that gets missed before a publish.
 *
 * The node's own status is deliberately ignored: callers already have it, and
 * conflating the two is what makes a single indicator ambiguous.
 */
export function hasUnpublishedDescendant(node: ElementNode): boolean {
  if (!isContainerNode(node)) {
    return false
  }

  return (node.children ?? []).some(
    (child) => isUnpublished(child.status) || hasUnpublishedDescendant(child),
  )
}
