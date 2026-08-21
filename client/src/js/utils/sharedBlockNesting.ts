import type { ElementNode } from '@/types/elements'
import { isContainerNode, isSharedBlockReferenceNode } from '@/types/elements'

/**
 * Whether `node` or anything below it is a shared block placement.
 *
 * Mirrors the server's `SharedBlockService::containsPlacement()`, so the
 * "Convert to shared block" action is never offered on a subtree the conversion
 * would refuse. The server check is the backstop, not the only one: a block
 * built with a nested placement fails SHARED_NESTING on its first publish and
 * cannot be repaired from the editor.
 *
 * Descent stops at a placement rather than walking into it — a block may not
 * contain another one, so there is nothing deeper to find.
 */
export function containsSharedBlockPlacement(node: ElementNode): boolean {
  if (isSharedBlockReferenceNode(node)) {
    return true
  }

  if (!isContainerNode(node)) {
    return false
  }

  return (node.children ?? []).some(containsSharedBlockPlacement)
}
