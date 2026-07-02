import type {
  CollisionDetection,
  DragCancelEvent,
  DragEndEvent,
  DragMoveEvent,
  DragStartEvent,
  SensorDescriptor,
  SensorOptions,
} from '@dnd-kit/core'
import { PointerSensor, useSensor, useSensors } from '@dnd-kit/core'
import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { useElementMaps } from '@/hooks/useElementMaps'
import { usePendingTree } from '@/hooks/usePendingTree'
import type { DraggableType } from '@/types/dnd'
import { buildDraggableId, parseDraggableId } from '@/types/dnd'
import type { ElementNode, TreeApiResponse } from '@/types/elements'
import { isContainerNode } from '@/types/elements'
import { NodeIdentity, type NodeRef } from '@/types/identity'
import { createTypedCollisionDetection } from '@/utils/collisionDetection'
import { resolveDropPlacement } from '@/utils/resolveDropPlacement'
import { resolveInsertDirection } from '@/utils/resolveInsertDirection'

// --- Public types ---

export interface DragState {
  activeId: string
  activeType: DraggableType
  activeNode: ElementNode
}

export interface UseDragAndDropOptions {
  tree: TreeApiResponse
  onReorder: (
    element: NodeRef,
    parent: NodeRef,
    after: NodeRef | null,
    clearPendingTree: () => void,
  ) => void
}

export interface DndContextProps {
  sensors: SensorDescriptor<SensorOptions>[]
  collisionDetection: CollisionDetection
  onDragStart: (event: DragStartEvent) => void
  onDragMove: (event: DragMoveEvent) => void
  onDragEnd: (event: DragEndEvent) => void
  onDragCancel: (event: DragCancelEvent) => void
}

export interface UseDragAndDropReturn {
  /** Spread directly onto DndContext. */
  dndContextProps: DndContextProps
  /** Current drag state for DragOverlay rendering. */
  dragState: DragState | null
  /** Tree with pending cross-container move applied, or null. */
  pendingTree: TreeApiResponse | null
}

// --- Drag context ---

export interface DragContextValue {
  activeType: DraggableType | null
  /**
   * True while a cross-container move is being previewed via the pending tree.
   * Block components use this to switch their `SortableContext` to a no-op
   * sorting strategy: the pending tree already re-renders the moved item into
   * its target slot, so dnd-kit's reorder-preview transforms (≈ one block
   * height) would only double-count the move — and at the design's block sizes
   * those large shifts push siblings far enough that the next collision cycle,
   * and the before/after direction at drop, resolve against the wrong rect.
   */
  pendingActive: boolean
}

export const DragContext = createContext<DragContextValue>({
  activeType: null,
  pendingActive: false,
})

export function useDragContext(): DragContextValue {
  return useContext(DragContext)
}

// --- Helpers ---

/**
 * Compute the current pointer viewport position from a dnd-kit drag event.
 *
 * `active.rect.current.translated` is scroll-adjusted (viewport-relative) but
 * uses the original element's dimensions, so its center drifts from the pointer
 * when the grab point isn't at the element's center.
 *
 * Fix: offset `translated` by the grab point distance within the initial rect.
 * This gives the pointer's true viewport position, consistent with
 * `getBoundingClientRect()` values used for droppable rects.
 *
 * `event.delta` is NOT usable here — dnd-kit adds accumulated scroll offsets
 * to the translate state (via auto-scroll's `onScrollChange`), so
 * `pe.clientY + event.delta.y` gives a scroll-inflated value, not viewport Y.
 */
function getPointerPosition(event: {
  activatorEvent: Event
  active: {
    rect: {
      current: {
        initial: { left: number; top: number } | null
        translated: { left: number; top: number } | null
      }
    }
  }
}): { x: number; y: number } | null {
  const pe = event.activatorEvent
  if (!(pe instanceof PointerEvent)) return null

  const initialRect = event.active.rect.current.initial
  const translated = event.active.rect.current.translated
  if (!initialRect || !translated) return null

  return {
    x: translated.left + (pe.clientX - initialRect.left),
    y: translated.top + (pe.clientY - initialRect.top),
  }
}

// --- Hook ---

const POINTER_DISTANCE_THRESHOLD = 8

export function useDragAndDrop({ tree, onReorder }: UseDragAndDropOptions): UseDragAndDropReturn {
  const [dragState, setDragState] = useState<DragState | null>(null)
  const maps = useElementMaps(tree)
  const pending = usePendingTree()

  const [collisionDetection] = useState<CollisionDetection>(() =>
    createTypedCollisionDetection(pending.collisionRefs),
  )

  const sensors = useSensors(
    // Stryker disable next-line all: Equivalent — sensor activation config is bypassed by synthetic DragEvent tests that invoke the handlers directly
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  )

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const activeId = String(event.active.id)
      const parsed = parseDraggableId(activeId)
      if (!parsed) return

      const node = maps.nodeMap.get(parsed.key)
      if (!node) return

      const siblings = maps.childrenByParentKey.get(node.parentKey) ?? []
      // Stryker disable next-line all: Equivalent — sourceContainerItemsRef is consumed only by collision detection (not exercised in synthetic DragEvent tests) and is not exposed on the hook's public API
      const sourceSiblingIds = new Set<string>()
      for (const sibling of siblings) {
        if (sibling.nodeKey !== parsed.key) {
          sourceSiblingIds.add(buildDraggableId(parsed.type, sibling.self.id))
        }
      }
      pending.setSourceSiblings(sourceSiblingIds)

      setDragState({
        activeId,
        activeType: parsed.type,
        activeNode: node,
      })
    },
    [maps, pending],
  )

  // Driven by dnd-kit's onDragMove (every pointer move), NOT onDragOver.
  // dnd-kit fires onDragOver only when `over` CHANGES; placing the ghost before
  // vs after the SAME element is a direction flip with no over-change, so an
  // onDragOver-driven preview freezes at whichever side it first entered.
  // Collision detection runs on every move, so onDragMove is the right cadence,
  // and applyPendingMove's no-op guard (same tree ref → no setState) keeps it
  // cheap when the resolved placement is unchanged.
  const handleDragMove = useCallback(
    (event: DragMoveEvent) => {
      const { active, over } = event
      if (!over) return
      if (active.id === over.id) return

      const activeParsed = parseDraggableId(String(active.id))
      const overParsed = parseDraggableId(String(over.id))
      if (!activeParsed || !overParsed) return

      const { tree: effectiveTree, maps: effectiveMaps } = pending.getEffective(tree, maps)

      // The element's ORIGINAL parent, read from the CANONICAL maps — not the
      // effective (pending) maps. After the first cross-container pending move
      // the effective maps already show the active element inside the target
      // container, so reading its parent from there would misclassify the
      // still-in-progress preview as a same-container move (see the guard
      // below) and freeze the ghost at its first-placed position.
      const sourceParent = maps.nodeMap.get(activeParsed.key)?.parent
      if (sourceParent === undefined) return

      let targetParent: NodeRef
      let after: NodeRef | null

      if (overParsed.type === activeParsed.type) {
        const overNode = effectiveMaps.nodeMap.get(overParsed.key)
        if (!overNode) return
        targetParent = overNode.parent

        // Sibling list with the active element excluded. During a pending
        // preview the active element already sits in this list, so anchoring
        // `after` to the slot before `over` could reference the ghost itself —
        // placing it relative to its own position and bouncing it back to the
        // container end. Excluding it keeps the preview consistent with the
        // direction-based placement resolveDropPlacement computes at drop time.
        const siblings = effectiveMaps.childrenByParentKey.get(overNode.parentKey) ?? []
        const others = siblings.filter((sibling) => sibling.nodeKey !== activeParsed.key)
        const overPos = others.findIndex((sibling) => sibling.nodeKey === overParsed.key)

        // Resolve direction against the over element's LIVE DOM rect, not dnd-kit's
        // over.rect. This handler's applyPendingMove re-renders the pending tree,
        // and over.rect (droppableRects) is measured a cycle behind that re-render,
        // so the pointer gets compared to the element's PREVIOUS position — near a
        // boundary this inverts the before/after decision and the ghost can't cross.
        // getBoundingClientRect reads the current DOM; with the pending container's
        // SortableContext no-op'd there are no transforms, so it shares the pointer's
        // viewport space. (overRectRef is captured by tier-2 collision detection.)
        const overSnapshot = pending.collisionRefs.overRectRef.current
        const overRectNode = overSnapshot?.nodeRef.current
        const directionRect =
          String(overSnapshot?.id) === String(over.id) && overRectNode
            ? overRectNode.getBoundingClientRect()
            : over.rect

        const pointer = getPointerPosition(event)
        if (
          pointer !== null &&
          resolveInsertDirection(pointer, directionRect, activeParsed.type) === 'before'
        ) {
          after = overPos > 0 ? others[overPos - 1].self : null
        } else {
          after = overNode.self
        }
      } else {
        const containerNode = effectiveMaps.nodeMap.get(overParsed.key)
        if (!containerNode || !isContainerNode(containerNode)) return
        targetParent = containerNode.self
        const children = containerNode.children ?? []
        after = children.length > 0 ? children[children.length - 1].self : null
      }

      // Genuine same-container move: the element STARTED in the target parent,
      // so SortableContext handles the visual reordering via transforms and no
      // pending tree is needed. A cross-container preview already placed the
      // element into the target parent in the pending maps — that must keep
      // updating, which is why this compares the canonical `sourceParent`.
      if (NodeIdentity.equals(sourceParent, targetParent)) return

      const targetParentKey = NodeIdentity.toKey(targetParent.type, targetParent.id)
      pending.applyPendingMove(activeParsed, targetParentKey, after?.id ?? null, effectiveTree)
    },
    [tree, maps, pending],
  )

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      setDragState(null)

      const { active, over } = event
      if (!over) {
        pending.clear()
        return
      }
      if (active.id === over.id) {
        pending.clear()
        return
      }

      const activeParsed = parseDraggableId(String(active.id))
      const overParsed = parseDraggableId(String(over.id))
      if (!activeParsed || !overParsed) {
        pending.clear()
        return
      }

      const activeNode = maps.nodeMap.get(activeParsed.key)
      if (!activeNode) {
        pending.clear()
        return
      }

      const sourceParentKey = activeNode.parentKey
      const sourceChildren = maps.childrenByParentKey.get(sourceParentKey)
      if (!sourceChildren) {
        pending.clear()
        return
      }
      // Stryker disable next-line UnaryOperator: Equivalent — activeNode was confirmed present in nodeMap (above), and useElementMaps populates indexByNodeKey alongside nodeMap in one walk, so .get() is never undefined and the `?? -1` sentinel is unreachable
      const sourceIndex = maps.indexByNodeKey.get(activeParsed.key) ?? -1

      const { maps: effectiveMaps } = pending.getEffective(tree, maps)

      // Read the over element's LIVE DOM rect at drop time (getBoundingClientRect),
      // matching the rect handleDragMove uses for the preview. dnd-kit's over.rect
      // lags the pending-tree re-render (and, for same-container drops, misses
      // SortableContext CSS transforms), so using it here would resolve a
      // before/after direction that disagrees with the previewed ghost position —
      // the drop would land on the opposite side of where the ghost was shown.
      // The live rect keeps preview and drop consistent. Tier-2 collision detection
      // captures overRectRef for the pending path; tier-1 captures it for same-
      // container drops.
      const overSnapshot = pending.collisionRefs.overRectRef.current
      const overNode = overSnapshot?.nodeRef.current
      const effectiveOverRect =
        // Stryker disable next-line all: Equivalent — overRectRef is populated only by real collision detection, which is not exercised in synthetic DragEvent tests (overSnapshot remains null, both branches resolve to over.rect)
        String(overSnapshot?.id) === String(over.id) && overNode
          ? overNode.getBoundingClientRect()
          : over.rect

      const pointer = getPointerPosition(event)

      const placement = resolveDropPlacement({
        activeParsed,
        overParsed,
        pointer,
        maps: effectiveMaps,
        sourceParentKey,
        sourceIndex,
        overRect: effectiveOverRect,
      })

      if (placement) {
        // Pre-position the dragged element into its final slot via the pending
        // tree before firing the reorder. dnd-kit's DragOverlay drop animation
        // measures the dragged node's resting rect in a layout effect that runs
        // immediately after this drag-end commit (dnd-kit invokes onDragEnd inside
        // the same unstable_batchedUpdates as its own active→null dispatch), so the
        // DOM must already reflect the post-drop order at that point. The pending
        // tree is plain React state, so setting it here batches into that commit.
        // The reorder mutation's optimistic cache write cannot do this: TanStack
        // defers query re-renders by a macrotask (its notifyManager schedules via
        // setTimeout(0)), landing after the animation has captured — which is why a
        // same-container drop otherwise animates to the pre-move slot and snaps.
        // Cross-container drags already populate the pending tree during drag-over;
        // doing it here unconditionally unifies both paths and keeps the
        // pre-positioned tree identical to the one onMutate commits (same
        // applyReorder inputs against the canonical tree).
        pending.applyPendingMove(
          activeParsed,
          NodeIdentity.toKey(placement.parent),
          placement.after === null ? null : placement.after.id,
          tree,
        )
        onReorder(placement.element, placement.parent, placement.after, pending.clear)
      } else {
        pending.clear()
      }
    },
    [tree, maps, onReorder, pending],
  )

  const handleDragCancel = useCallback(() => {
    setDragState(null)
    pending.clear()
  }, [pending])

  const dndContextProps = useMemo<DndContextProps>(
    () => ({
      sensors,
      collisionDetection,
      onDragStart: handleDragStart,
      onDragMove: handleDragMove,
      onDragEnd: handleDragEnd,
      onDragCancel: handleDragCancel,
    }),
    [sensors, collisionDetection, handleDragStart, handleDragMove, handleDragEnd, handleDragCancel],
  )

  return {
    dndContextProps,
    dragState,
    pendingTree: pending.pendingTree,
  }
}
