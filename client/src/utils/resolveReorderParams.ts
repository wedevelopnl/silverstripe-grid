import type { ReorderElementParams } from '@/api/endpoints'
import type { ElementMaps } from '@/hooks/useElementMaps'
import { parseDraggableId } from '@/types/dnd'
import type { NodeKey, NodeRef } from '@/types/identity'

export interface ReorderContext {
  /** Composite dnd-kit ID of the element being moved — also its NodeKey. */
  activeId: NodeKey
  /** NodeRef of the target parent (scoped identity). */
  targetParent: NodeRef
  /** NodeKey of the target parent (for map lookups). */
  targetParentKey: NodeKey
  /** Insertion index in the target container's final order. */
  overIndex: number
  /** Ordered composite IDs of items in the target container (reflects final order). */
  containerItems: NodeKey[]
  /** NodeKey of the source container. */
  sourceParentKey: NodeKey
  /** Original index in the source container. */
  sourceIndex: number
  /** Maps into the effective tree — used to resolve `after` NodeRefs. */
  maps: ElementMaps
}

/**
 * Map drag-and-drop context to the backend API's reorder parameters.
 *
 * Returns null when the move is a no-op (same container, same index) or the
 * active ID is unparseable.
 */
export function resolveReorderParams(context: ReorderContext): ReorderElementParams | null {
  const parsed = parseDraggableId(context.activeId)
  if (!parsed) return null

  if (
    context.sourceParentKey === context.targetParentKey &&
    context.sourceIndex === context.overIndex
  ) {
    return null
  }

  const afterKey = resolveAfterKey(context)
  const afterNode = afterKey === null ? null : (context.maps.nodeMap.get(afterKey) ?? null)

  return {
    element: { type: parsed.type, id: parsed.id },
    parent: context.targetParent,
    after: afterNode ? afterNode.self : null,
  }
}

/**
 * Walk backwards from `overIndex - 1` to find the first non-active item, which
 * becomes the `after` anchor. Returns null when the insertion is at the head
 * of the target container.
 */
function resolveAfterKey(context: ReorderContext): NodeKey | null {
  for (let i = context.overIndex - 1; i >= 0; i--) {
    const item = context.containerItems[i]
    if (item === context.activeId) continue
    return item
  }
  return null
}
