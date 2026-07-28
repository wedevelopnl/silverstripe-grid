import type { MutableRefObject } from 'react'
import { useCallback, useMemo, useRef, useState } from 'react'
import type { ElementMaps } from '@/hooks/useElementMaps'
import { buildMaps } from '@/hooks/useElementMaps'
import type { ParsedDraggableId } from '@/types/dnd'
import { buildDraggableId } from '@/types/dnd'
import type { TreeApiResponse } from '@/types/elements'
import { NodeIdentity, type NodeKey, type NodeRef } from '@/types/identity'
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

  /**
   * Reposition the active element into a target slot, producing a fresh pending
   * tree. Used both for the cross-container live preview (during drag-over) and
   * to pre-position the dragged node at drop time so dnd-kit's DragOverlay drop
   * animation measures the destination, not the origin (see the drag-end handler
   * in useDragAndDrop).
   */
  applyPendingMove(
    activeParsed: ParsedDraggableId,
    targetParentKey: NodeKey,
    afterElementId: number | null,
    effectiveTree: TreeApiResponse,
    effectiveMaps: ElementMaps,
  ): void

  /** Set source container siblings (called on drag start). */
  setSourceSiblings(siblings: ReadonlySet<string | number>): void

  /** Get effective tree/maps (pending or canonical). */
  getEffective(
    canonicalTree: TreeApiResponse,
    canonicalMaps: ElementMaps,
  ): { tree: TreeApiResponse; maps: ElementMaps }

  /**
   * Read the active element's placement from the current pending (cross-container
   * preview) tree: its target parent and the sibling it sits after (null = first).
   *
   * This is what the ghost visibly shows. The drag-end handler commits THIS
   * rather than re-resolving from dnd-kit's drag-end collision — that collision
   * runs against the already-mutated pending DOM and can pick a different `over`
   * (with a stale `over.rect`) than every drag-move used, landing the element
   * away from the preview. Returns null when no pending preview is active (i.e.
   * a same-container move, which resolves by index instead).
   */
  getActivePlacement(activeKey: NodeKey): { parent: NodeRef; after: NodeRef | null } | null

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
      effectiveMaps: ElementMaps,
    ): void => {
      // Entering the pending (tier-2) path: a tier-1 snapshot must not survive — see dnd-guide invariant #5.
      overRectRef.current = null
      const activeKey = NodeIdentity.toKey(activeParsed.type, activeParsed.id)
      const afterKey =
        afterElementId === null ? null : NodeIdentity.toKey(activeParsed.type, afterElementId)

      const newTree = applyReorder(
        effectiveTree,
        effectiveMaps,
        activeKey,
        targetParentKey,
        afterKey,
      )
      if (newTree === effectiveTree) return

      const newMaps = buildMaps(newTree)
      pendingTreeRef.current = newTree
      pendingMapsRef.current = newMaps
      hasPendingMoveRef.current = true

      const targetSiblings = newMaps.childrenByParentKey.get(targetParentKey) ?? []
      pendingContainerItemsRef.current = new Set(
        targetSiblings.map((n) => buildDraggableId(activeParsed.type, n.self.id)),
      )

      setPendingTree(newTree)
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

  const getActivePlacement = useCallback(
    (activeKey: NodeKey): { parent: NodeRef; after: NodeRef | null } | null => {
      const maps = pendingMapsRef.current
      if (maps === null) return null

      const node = maps.nodeMap.get(activeKey)
      if (!node) return null

      const siblings = maps.childrenByParentKey.get(node.parentKey) ?? []
      const index = siblings.findIndex((sibling) => sibling.nodeKey === activeKey)
      // The sibling immediately before the active element is the `after` anchor;
      // at the head of the container there is none, so the anchor is null.
      const after = index > 0 ? siblings[index - 1].self : null

      return { parent: node.parent, after }
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

  return {
    pendingTree,
    collisionRefs,
    applyPendingMove,
    setSourceSiblings,
    getEffective,
    getActivePlacement,
    clear,
  }
}
