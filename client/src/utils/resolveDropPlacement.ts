import type { ReorderElementParams } from '@/api/endpoints'
import type { ElementMaps } from '@/hooks/useElementMaps'
import type { ParsedDraggableId, ViewportRect } from '@/types/dnd'
import { isContainerNode } from '@/types/elements'
import { NodeIdentity, type NodeKey } from '@/types/identity'
import { resolveInsertDirection } from '@/utils/resolveInsertDirection'
import { resolveReorderParams } from '@/utils/resolveReorderParams'

export interface DropContext {
  readonly activeParsed: ParsedDraggableId
  readonly overParsed: ParsedDraggableId
  readonly pointer: { readonly x: number; readonly y: number } | null
  readonly maps: ElementMaps
  readonly sourceParentKey: NodeKey
  readonly sourceIndex: number
  readonly overRect: ViewportRect
}

/**
 * Pure function that resolves a drag-and-drop event into API reorder parameters.
 *
 * Same-container moves use index-based placement (SortableContext handles the
 * visual shuffle). Cross-container moves apply pointer-based direction
 * (before/after) relative to the over element's rect.
 *
 * Returns null for no-ops (same position) or invalid states (missing nodes).
 */
export function resolveDropPlacement(ctx: DropContext): ReorderElementParams | null {
  const { activeParsed, overParsed, pointer, maps, sourceParentKey, sourceIndex, overRect } = ctx

  const activeKey = NodeIdentity.toKey(activeParsed.type, activeParsed.id)

  if (overParsed.type === activeParsed.type) {
    const overKey = NodeIdentity.toKey(overParsed.type, overParsed.id)
    const overNode = maps.nodeMap.get(overKey)
    if (!overNode) return null

    const targetParent = overNode.parent
    const targetParentKey = overNode.parentKey
    const siblings = maps.childrenByParentKey.get(targetParentKey) ?? []
    const filtered = collectSiblingKeysExcept(siblings, activeKey)

    let insertIndex: number
    if (sourceParentKey === targetParentKey) {
      // Same container: use the over element's index in the full list.
      // O(1) via the precomputed index map.
      const overOriginalIdx = maps.indexByNodeKey.get(overKey) ?? -1
      insertIndex = overOriginalIdx === -1 ? filtered.length : overOriginalIdx
      filtered.splice(insertIndex, 0, activeKey)
    } else {
      // Cross-container: overKey is not the active key, so its index in the
      // filtered list equals its index in the (already filtered) siblings.
      const overIdx = filtered.indexOf(overKey)
      if (overIdx === -1) {
        insertIndex = filtered.length
      } else {
        insertIndex = overIdx
        if (
          pointer !== null &&
          resolveInsertDirection(pointer, overRect, activeParsed.type) === 'after'
        ) {
          insertIndex += 1
        }
      }

      const clampedIndex = Math.min(insertIndex, filtered.length)
      filtered.splice(clampedIndex, 0, activeKey)
    }

    return resolveReorderParams({
      activeId: activeKey,
      targetParent,
      targetParentKey,
      overIndex: filtered.indexOf(activeKey),
      containerItems: filtered,
      sourceParentKey,
      sourceIndex,
      maps,
    })
  }

  // Over a container — drop at its end.
  const overKey = NodeIdentity.toKey(overParsed.type, overParsed.id)
  const containerNode = maps.nodeMap.get(overKey)
  if (!containerNode || !isContainerNode(containerNode)) return null

  const targetParent = containerNode.self
  const targetParentKey = NodeIdentity.toKey(targetParent)
  const children = containerNode.children ?? []
  const filtered = collectSiblingKeysExcept(children, activeKey)

  filtered.push(activeKey)

  return resolveReorderParams({
    activeId: activeKey,
    targetParent,
    targetParentKey,
    overIndex: filtered.indexOf(activeKey),
    containerItems: filtered,
    sourceParentKey,
    sourceIndex,
    maps,
  })
}

/**
 * Single-pass collection of sibling NodeKeys with one excluded. Replaces a
 * `.map().filter()` pair so the array is allocated and walked only once.
 */
function collectSiblingKeysExcept(
  siblings: readonly { readonly nodeKey: NodeKey }[],
  excludedKey: NodeKey,
): NodeKey[] {
  const out: NodeKey[] = []
  for (const sibling of siblings) {
    if (sibling.nodeKey !== excludedKey) out.push(sibling.nodeKey)
  }
  return out
}
