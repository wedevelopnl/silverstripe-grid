import { type ElementNode, isContainerNode } from '@/types/elements'

/**
 * Whether anything *below* `node` carries unpublished changes.
 *
 * The editor draws two different marks: an element that changed itself gets the
 * brand "Modified" badge, while an element that merely contains such a change
 * gets the orange ring and dot. Without this walk the second mark is impossible
 * — a modified block inside a collapsed section would be invisible until the
 * editor expanded it, which is exactly the state that gets missed before a
 * publish.
 *
 * The node's own status is deliberately ignored: callers already have it, and
 * conflating the two is what makes a single indicator ambiguous.
 */
export function hasModifiedDescendant(node: ElementNode): boolean {
  if (!isContainerNode(node)) {
    return false
  }

  return (node.children ?? []).some(
    (child) => child.status === 'modified' || hasModifiedDescendant(child),
  )
}
