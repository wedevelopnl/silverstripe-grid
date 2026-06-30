import type { MutableRefObject } from 'react'
import { useCallback, useMemo, useRef, useState } from 'react'
import type { ElementMaps } from '@/hooks/useElementMaps'
import { buildMaps } from '@/hooks/useElementMaps'
import type { ParsedDraggableId } from '@/types/dnd'
import { buildDraggableId } from '@/types/dnd'
import type { TreeApiResponse } from '@/types/elements'
import { NodeIdentity, type NodeKey } from '@/types/identity'
import { applyReorder } from '@/utils/applyReorder'
import type { OverRectSnapshot } from '@/utils/collisionDetection'

export interface CollisionRefs {
  readonly hasPendingMoveRef: MutableRefObject<boolean>
  readonly pendingContainerItemsRef: MutableRefObject<ReadonlySet<string | number> | null>
  readonly sourceContainerItemsRef: MutableRefObject<ReadonlySet<string | number> | null>
  readonly overRectRef: MutableRefObject<OverRectSnapshot | null>
}

export interface UsePendingTreeReturn {
  /** React state for rendering — the tree with pending move applied, or null. */
  pendingTree: TreeApiResponse | null

  /** Refs exposed for collision detection (read-only from its perspective). */
  collisionRefs: CollisionRefs

  /** Apply a cross-container move. Returns new tree/maps, or null if no-op. */
  applyPendingMove(
    activeParsed: ParsedDraggableId,
    targetParentKey: NodeKey,
    afterElementId: number | null,
    effectiveTree: TreeApiResponse,
  ): { tree: TreeApiResponse; maps: ElementMaps } | null

  /** Set source container siblings (called on drag start). */
  setSourceSiblings(siblings: ReadonlySet<string | number>): void

  /** Get effective tree/maps (pending or canonical). */
  getEffective(
    canonicalTree: TreeApiResponse,
    canonicalMaps: ElementMaps,
  ): { tree: TreeApiResponse; maps: ElementMaps }

  /** Reset all pending state. */
  clear(): void
}

/**
 * Encapsulate pending tree state and collision detection refs for
 * cross-container drag-and-drop moves.
 */
export function usePendingTree(): UsePendingTreeReturn {
  const [pendingTree, setPendingTree] = useState<TreeApiResponse | null>(null)
  const pendingTreeRef = useRef<TreeApiResponse | null>(null)
  const pendingMapsRef = useRef<ElementMaps | null>(null)
  const hasPendingMoveRef = useRef(false)
  const overRectRef = useRef<OverRectSnapshot | null>(null)
  const pendingContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null)
  const sourceContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null)

  const collisionRefs = useMemo<CollisionRefs>(
    () => ({
      hasPendingMoveRef,
      pendingContainerItemsRef,
      sourceContainerItemsRef,
      overRectRef,
    }),
    [],
  )

  const applyPendingMove = useCallback(
    (
      activeParsed: ParsedDraggableId,
      targetParentKey: NodeKey,
      afterElementId: number | null,
      effectiveTree: TreeApiResponse,
    ): { tree: TreeApiResponse; maps: ElementMaps } | null => {
      // Entering the pending (tier-2) path: a tier-1 snapshot must not survive — see dnd-guide invariant #5.
      overRectRef.current = null
      const activeKey = NodeIdentity.toKey(activeParsed.type, activeParsed.id)
      const afterKey =
        afterElementId === null ? null : NodeIdentity.toKey(activeParsed.type, afterElementId)

      const newTree = applyReorder(effectiveTree, activeKey, targetParentKey, afterKey)
      if (newTree === effectiveTree) return null

      const newMaps = buildMaps(newTree)
      pendingTreeRef.current = newTree
      pendingMapsRef.current = newMaps
      hasPendingMoveRef.current = true

      const targetSiblings = newMaps.childrenByParentKey.get(targetParentKey) ?? []
      pendingContainerItemsRef.current = new Set(
        targetSiblings.map((n) => buildDraggableId(activeParsed.type, n.self.id)),
      )

      setPendingTree(newTree)
      return { tree: newTree, maps: newMaps }
    },
    [],
  )

  const setSourceSiblings = useCallback((siblings: ReadonlySet<string | number>) => {
    sourceContainerItemsRef.current = siblings
  }, [])

  const getEffective = useCallback(
    (
      canonicalTree: TreeApiResponse,
      canonicalMaps: ElementMaps,
    ): { tree: TreeApiResponse; maps: ElementMaps } => {
      if (pendingTreeRef.current !== null && pendingMapsRef.current !== null) {
        return { tree: pendingTreeRef.current, maps: pendingMapsRef.current }
      }
      return { tree: canonicalTree, maps: canonicalMaps }
    },
    [],
  )

  const clear = useCallback(() => {
    pendingTreeRef.current = null
    pendingMapsRef.current = null
    hasPendingMoveRef.current = false
    overRectRef.current = null
    pendingContainerItemsRef.current = null
    sourceContainerItemsRef.current = null
    setPendingTree(null)
  }, [])

  return { pendingTree, collisionRefs, applyPendingMove, setSourceSiblings, getEffective, clear }
}
