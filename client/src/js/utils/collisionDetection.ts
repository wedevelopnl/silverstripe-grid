import {
  type Collision,
  type CollisionDetection,
  closestCenter,
  type DroppableContainer,
} from '@dnd-kit/core'

import { getDraggableType, PARENT_CONTAINER_TYPE } from '@/types/dnd'

/**
 * Like closestCenter, but reads live DOM rects via getBoundingClientRect()
 * instead of using dnd-kit's droppableRects (which are pre-CSS-transform
 * and stale after SortableContext shifts items visually).
 *
 * Two extra refinements over stock closestCenter:
 *
 * 1. Reference point: prefers the live pointer (`pointerCoordinates`) over the
 *    collision rect's center. The collision rect is the DragOverlay's
 *    translated rect; its center drifts from the cursor by the grab offset
 *    (grabbing a wide block at its left edge shifts the center far to the
 *    right). The pointer is exactly where the user is aiming.
 * 2. Containment ranks first: a target whose live rect actually surrounds the
 *    pointer beats a neighbour that merely has a closer center. Without this,
 *    tall blocks or wide inter-sibling gutters (the design's "+ add" slots)
 *    push a neighbour's center close enough to the cursor that pure
 *    center-distance picks the wrong sibling — which, via the parent-container
 *    fallback, lands the drop at the container's end instead of where aimed.
 *
 * Returns only the winning collision: dnd-kit derives `over` from the first
 * collision and nothing consumes the rest, so ranking is done as a single-pass
 * selection (containment first, then squared center distance) instead of
 * building and sorting the full candidate list on every pointer move.
 */
const closestCenterLive: CollisionDetection = (args) => {
  const { collisionRect, droppableContainers, pointerCoordinates } = args
  const refX = pointerCoordinates?.x ?? collisionRect.left + collisionRect.width / 2
  const refY = pointerCoordinates?.y ?? collisionRect.top + collisionRect.height / 2

  let winner: Collision | null = null
  let winnerContains = false
  let winnerValue = Number.POSITIVE_INFINITY

  for (const container of droppableContainers) {
    const domNode = container.node.current
    if (!domNode) continue

    const rect = domNode.getBoundingClientRect()
    const dx = refX - (rect.left + rect.width / 2)
    const dy = refY - (rect.top + rect.height / 2)
    const value = dx * dx + dy * dy
    const contains =
      refX >= rect.left && refX <= rect.right && refY >= rect.top && refY <= rect.bottom

    // Containment outranks distance; within the same band, closer center wins.
    // Strict `<` keeps the first-registered candidate on exact ties, matching
    // the stable sort this replaced.
    const better = contains !== winnerContains ? contains : value < winnerValue
    if (better) {
      winner = { id: container.id, data: { droppableContainer: container, value } }
      winnerContains = contains
      winnerValue = value
    }
  }

  return winner === null ? [] : [winner]
}

/**
 * Returns only same-type sibling containers for the active draggable.
 */
export function filterSiblings(
  activeId: string,
  containers: DroppableContainer[],
): DroppableContainer[] {
  const activeType = getDraggableType(activeId)
  if (activeType === null) return []

  return containers.filter((container) => getDraggableType(String(container.id)) === activeType)
}

/**
 * Returns only parent-type containers for the active draggable.
 * For sections (parent = 'root'), returns containers with unparseable IDs.
 */
export function filterParentContainers(
  activeId: string,
  containers: DroppableContainer[],
): DroppableContainer[] {
  const activeType = getDraggableType(activeId)
  if (activeType === null) return []

  const parentType = PARENT_CONTAINER_TYPE[activeType]

  return containers.filter((container) => {
    const containerType = getDraggableType(String(container.id))

    if (parentType === 'page') return containerType === null

    return containerType === parentType
  })
}

/**
 * Center-crossing collision strategy for sibling reordering.
 *
 * Detects a collision only when the collision rect center has crossed a
 * direction-aware threshold on the target. Uses `active.rect.current.initial`
 * for the starting position and the current `collisionRect` for the
 * current position.
 *
 * Compensates for DragOverlay measurement asymmetry: when a DragOverlay is
 * mounted, dnd-kit uses its compact rect (e.g. 53px header) as both the
 * initial rect and collision rect, not the full active element's rect
 * (e.g. 350px section). The crossing threshold is placed at the target's
 * near edge (closest to the initial position) plus half the collision rect
 * height, clamped to the target center. This naturally adapts:
 *
 * - When collisionRect ≈ target size (rows): threshold ≈ target center,
 *   providing strict ghost-jump prevention.
 * - When collisionRect << target size (sections): threshold ≈ target's
 *   near edge, making it reachable by the compact collision rect.
 *
 * Includes a pointer-based overlap gate with axis-dependent margins:
 * 150px vertically (auto-scroll drift) and 50px horizontally. Uses
 * pointer coordinates (viewport-relative) to avoid auto-scroll drift
 * between collisionRect (sensor-delta-based) and droppableRects.
 *
 * Returns only the crossing target closest to the collision-rect center:
 * dnd-kit derives `over` from the first collision and nothing consumes the
 * rest, so the closest candidate is selected in a single pass instead of
 * sorting all crossing targets on every pointer move.
 */
export const centerCrossing: CollisionDetection = (args) => {
  const { active, collisionRect, droppableContainers, droppableRects, pointerCoordinates } = args

  const initialRect = active.rect.current.initial
  if (initialRect === null) return []

  const initialCX = initialRect.left + initialRect.width / 2
  const initialCY = initialRect.top + initialRect.height / 2

  // Use whichever position — pointer or collisionRect center — is furthest
  // along the drag direction. The collisionRect center drifts from the
  // pointer when the grab point isn't at the DragOverlay's center: grabbing
  // a column header at its left edge shifts the center rightward, helping
  // rightward drags but hurting leftward ones. Using the maximum advance
  // ensures the threshold is reachable regardless of grab offset.
  const crCX = collisionRect.left + collisionRect.width / 2
  const crCY = collisionRect.top + collisionRect.height / 2
  const ptrX = pointerCoordinates?.x ?? crCX
  const ptrY = pointerCoordinates?.y ?? crCY

  let winner: Collision | null = null
  let winnerValue = Number.POSITIVE_INFINITY

  for (const container of droppableContainers) {
    const rect = droppableRects.get(container.id)
    if (rect === undefined) continue

    // No proximity gate here. centerCrossing is only called for
    // same-container sibling reordering (no pending cross-container move).
    // The directional threshold crossing below inherently limits detection
    // to targets the drag has actually moved past. A proximity gate would
    // be counterproductive because:
    //
    // 1. droppableRects use getTransformAgnosticClientRect (strips CSS
    //    transforms), while pointerCoordinates are viewport-relative
    //    (includes transforms) — coordinate space mismatch.
    // 2. Live DOM rects (getBoundingClientRect) include SortableContext's
    //    visual swap transforms, moving the target element away from the
    //    pointer after the first detection — causing oscillation between
    //    detected/not-detected states.

    const targetCX = rect.left + rect.width / 2
    const targetCY = rect.top + rect.height / 2

    // Direction-aware threshold adapts to the overlay-to-target size ratio.
    //
    // Dragging TOWARD target: threshold at the target's near edge + half
    // the overlay height/width. When overlay ≈ target size, this
    // approximates the center (strict ghost-jump prevention). When
    // overlay << target, threshold sits near the edge (reachable by the
    // compact overlay, and the pointer reaches it even sooner).
    //
    // Dragging AWAY from target: use the stricter of the edge-based
    // threshold and the target center.
    const thresholdY =
      initialCY < targetCY
        ? rect.top + collisionRect.height / 2
        : Math.min(rect.top + rect.height - collisionRect.height / 2, targetCY)

    const thresholdX =
      initialCX < targetCX
        ? rect.left + collisionRect.width / 2
        : Math.min(rect.left + rect.width - collisionRect.width / 2, targetCX)

    // Use the position furthest along the drag direction for crossing.
    // Moving toward target (initialCX < targetCX): use the rightmost
    // position. Moving away (initialCX > targetCX): use the leftmost.
    // This ensures the threshold is reachable regardless of grab offset.
    const currentCX = initialCX < targetCX ? Math.max(crCX, ptrX) : Math.min(crCX, ptrX)
    const currentCY = initialCY < targetCY ? Math.max(crCY, ptrY) : Math.min(crCY, ptrY)

    const crossedY =
      (initialCY > thresholdY && currentCY <= thresholdY) ||
      (initialCY < thresholdY && currentCY >= thresholdY)

    const crossedX =
      (initialCX > thresholdX && currentCX <= thresholdX) ||
      (initialCX < thresholdX && currentCX >= thresholdX)

    // Overlap gate: the pointer must be near the target rect.
    // Uses pointer coordinates (viewport-relative) to avoid the
    // coordinate-space mismatch between collisionRect (sensor-delta-
    // based, frozen during auto-scroll) and droppableRects (viewport-
    // relative, shift with auto-scroll).
    //
    // Axis-dependent margins:
    // - Y: 150px — accommodates auto-scroll drift. When the pointer
    //   sits near the scrollable container's edge, dnd-kit auto-scrolls
    //   the content, shifting droppableRects vertically while the
    //   pointer stays put. Observed drift: ~60px in 10 cycles.
    // - X: 50px — tight enough to avoid matching elements in adjacent
    //   columns (column gaps are typically 100px+ in narrow layouts).
    const MARGIN_X = 50
    const MARGIN_Y = 150
    const overlapX = ptrX > rect.left - MARGIN_X && ptrX < rect.left + rect.width + MARGIN_X
    const overlapY = ptrY > rect.top - MARGIN_Y && ptrY < rect.top + rect.height + MARGIN_Y

    if ((crossedY || crossedX) && overlapX && overlapY) {
      const dx = crCX - targetCX
      const dy = crCY - targetCY
      const value = dx * dx + dy * dy

      // Strict `<` keeps the first-registered candidate on exact ties,
      // matching the stable sort this replaced.
      if (value < winnerValue) {
        winner = { id: container.id, data: { droppableContainer: container, value } }
        winnerValue = value
      }
    }
  }

  return winner === null ? [] : [winner]
}

export interface OverRectSnapshot {
  id: string | number
  /** DOM node ref — dereference at consumption time and call
   * getBoundingClientRect() for a rect that's always in sync with
   * pointer viewport coordinates (including SortableContext CSS transforms).
   * Stored as a ref (not raw HTMLElement) so React re-renders that replace
   * the DOM element are reflected automatically. */
  nodeRef: { readonly current: HTMLElement | null }
}

export interface TypedCollisionDetectionOptions {
  hasPendingMoveRef: { current: boolean }
  /** Set of composite droppable IDs belonging to the active item's pending container. */
  pendingContainerItemsRef?: { current: ReadonlySet<string | number> | null }
  /** Set of composite droppable IDs in the active item's source (original) container. */
  sourceContainerItemsRef?: { current: ReadonlySet<string | number> | null }
  /** Updated on every collision detection cycle with the winning element's rect. */
  overRectRef?: { current: OverRectSnapshot | null }
}

/**
 * Factory that creates a type-aware collision detection strategy for dnd-kit.
 *
 * Accepts a `hasPendingMoveRef` that switches sibling detection strategy:
 * - `false` (default): centerCrossing for siblings with overlap guard —
 *   prevents ghost jumps during same-container reordering.
 * - `true`: closestCenter for siblings, skip overlap guard — after a pending
 *   cross-container move, layout shifts invalidate centerCrossing thresholds.
 *   closestCenter is purely distance-based and unaffected by layout shifts.
 *
 * Parent container fallback (pass 2) always uses closestCenter.
 */
export function createTypedCollisionDetection(
  options: TypedCollisionDetectionOptions,
): CollisionDetection {
  // Track whether centerCrossing has detected a sibling during this drag.
  // Used to distinguish "threshold not yet crossed" (ghost-jump prevention)
  // from "centerCrossing missed due to stale droppableRects" (maintain over).
  // Stryker disable next-line BooleanLiteral: Equivalent — hadSiblingHit is only read inside the pointer-inside-source-sibling guard (sourceItems non-empty Set); whenever that holds, the first invocation's reset (lastSourceItems starts undefined) always assigns false before any read, so the initializer value is never observable
  let hadSiblingHit = false
  let lastSourceItems: ReadonlySet<string | number> | null | undefined

  return (args) => {
    const activeId = String(args.active.id)

    // Reset between drags: sourceContainerItemsRef is replaced on each
    // drag start and set to null on drag end/cancel.
    const currentSourceItems = options.sourceContainerItemsRef?.current
    if (currentSourceItems !== lastSourceItems) {
      hadSiblingHit = false
      lastSourceItems = currentSourceItems
    }

    // dnd-kit v6 does not exclude the active item from droppableContainers.
    // Its original-position rect remains registered as a droppable, so
    // closestCenter can return it as the closest target — causing a no-op drop.
    const nonActiveContainers = args.droppableContainers.filter(
      (container) => container.id !== args.active.id,
    )

    /**
     * Store the winning collision's DOM node for direction comparison at drop time.
     *
     * Stores the node reference rather than a static rect snapshot. The consumer
     * reads getBoundingClientRect() at the moment of comparison (drop time),
     * guaranteeing the rect and pointer are always in the same viewport
     * coordinate space — including any SortableContext CSS transforms.
     *
     * Every collision produced here (and by dnd-kit's closestCenter) carries its
     * DroppableContainer in `data`, so the winner's node needs no id lookup.
     */
    const captureWinnerNode = (collisions: Collision[]) => {
      if (options.overRectRef && collisions.length > 0) {
        const winner = collisions[0]
        const container = winner.data?.droppableContainer as DroppableContainer | undefined
        if (container?.node.current) {
          options.overRectRef.current = {
            id: winner.id,
            nodeRef: container.node as { readonly current: HTMLElement | null },
          }
        }
      }
      return collisions
    }

    const siblings = filterSiblings(activeId, nonActiveContainers)

    // Source-container siblings. Hoisted out of the no-pending branch below
    // because pass 2's lost-lock recovery needs the same set.
    const sourceItems = options.sourceContainerItemsRef?.current ?? null
    const sameContainerSiblings =
      sourceItems !== null && sourceItems.size > 0
        ? siblings.filter((s) => sourceItems.has(s.id))
        : siblings

    if (options.hasPendingMoveRef.current) {
      // After a pending cross-container move, SortableContext CSS transforms
      // shift items visually, but droppableRects reflect pre-transform DOM
      // positions. closestCenter uses these stale rects and picks the wrong
      // element. Instead, read live DOM rects via getBoundingClientRect()
      // which includes CSS transforms.
      //
      // Also filter to only siblings in the pending container to prevent
      // wrong-container bouncing.
      const pendingItems = options.pendingContainerItemsRef?.current
      const pendingSiblings =
        pendingItems !== null && pendingItems !== undefined
          ? siblings.filter((s) => pendingItems.has(s.id))
          : siblings

      const siblingCollisions = closestCenterLive({
        ...args,
        droppableContainers: pendingSiblings,
      })

      if (siblingCollisions.length > 0) {
        // Capture the winner's live DOM node so both handleDragMove (preview)
        // and handleDragEnd (drop) read a fresh getBoundingClientRect() for the
        // before/after DIRECTION. dnd-kit's over.rect (droppableRects) lags the
        // pending-tree re-render by a cycle, so near a boundary it inverts the
        // direction — the ghost can't cross a sibling, and a drop would land on
        // the opposite side of the previewed ghost. Reading the same live rect
        // in both places keeps the preview and the committed drop consistent.
        return captureWinnerNode(siblingCollisions)
      }
    } else {
      // Pass 1: prefer sibling collisions — centerCrossing requires the
      // pointer to cross the midpoint threshold, preventing ghost jumps.
      // Only check same-container siblings (via sourceContainerItemsRef)
      // to prevent false positives from elements in adjacent containers
      // that share a similar Y position. Cross-container moves are handled
      // by the parent container fallback in Pass 2.
      //
      // Exception: when the source container has no other siblings (source
      // depletion, e.g. dragging the only row out of a section), use ALL
      // siblings so centerCrossing can detect the target container's
      // siblings directly. Without this, the parent container fallback
      // would always fire, placing the item at the container's end instead
      // of at the pointer's position relative to target siblings.
      const siblingCollisions = centerCrossing({
        ...args,
        droppableContainers: sameContainerSiblings,
      })

      if (siblingCollisions.length > 0) {
        hadSiblingHit = true
        return captureWinnerNode(siblingCollisions)
      }

      // Guard: if the pointer is inside a SOURCE-container sibling rect (but
      // the crossing threshold wasn't met), return empty rather than falling
      // through to parent containers. Returning a parent container as `over`
      // causes SortableContext's sorting strategy to produce incorrect
      // transforms (the parent ID isn't in the items array → overIndex = -1).
      //
      // Only checks source-container siblings (via sourceContainerItemsRef)
      // to allow parent-container fallback during cross-container moves where
      // the pointer enters a new container's siblings.
      //
      // Uses pointer coordinates (viewport-relative) rather than collision
      // rect center because dnd-kit's scroll-adjusted translate can drift
      // from the droppable rect coordinate space after panel scrolling.
      const { pointerCoordinates, droppableRects } = args

      if (pointerCoordinates && sourceItems && sourceItems.size > 0) {
        const pointerInsideSourceSibling = sameContainerSiblings.some((sibling) => {
          const rect = droppableRects.get(sibling.id)
          if (rect === undefined) return false

          return (
            pointerCoordinates.x >= rect.left &&
            pointerCoordinates.x <= rect.left + rect.width &&
            pointerCoordinates.y >= rect.top &&
            pointerCoordinates.y <= rect.top + rect.height
          )
        })

        if (pointerInsideSourceSibling) {
          if (hadSiblingHit) {
            // centerCrossing previously detected a sibling but missed this
            // cycle (stale droppableRects during re-render). Use live DOM
            // rects to find the nearest source sibling and maintain `over`
            // state, preventing a spurious over=null reset that would cause
            // handleDragEnd to skip the reorder.
            const liveCollisions = closestCenterLive({
              ...args,
              droppableContainers: sameContainerSiblings,
            })

            if (liveCollisions.length > 0) {
              return captureWinnerNode(liveCollisions)
            }
          }

          return []
        }
      }
    }

    // Pass 2: fall back to parent container collisions.
    // Containment-first: prefer the parent whose rect contains the pointer.
    // closestCenter uses center distance, which biases toward smaller
    // containers when the cursor is near the boundary between a tall and
    // short section — the short section's center is closer even though the
    // cursor is visually inside the tall section.
    const parents = filterParentContainers(activeId, nonActiveContainers)

    if (args.pointerCoordinates) {
      const pointer = args.pointerCoordinates
      const containingParent = parents.find((parent) => {
        const rect = args.droppableRects.get(parent.id)
        if (rect === undefined) return false
        return (
          pointer.x >= rect.left &&
          pointer.x <= rect.left + rect.width &&
          pointer.y >= rect.top &&
          pointer.y <= rect.top + rect.height
        )
      })
      if (containingParent) {
        return captureWinnerNode([
          {
            id: containingParent.id,
            data: { droppableContainer: containingParent, value: 0 },
          },
        ])
      }
    }

    // Lost-lock recovery: centerCrossing locked onto a sibling earlier in this
    // drag, but the pointer now sits inside no parent rect at all. Auto-scroll
    // is the cause — it shifts droppableRects out from under a stationary
    // pointer (observed: ~190px, well past centerCrossing's 150px MARGIN_Y),
    // so the sibling stops matching even though SortableContext is still
    // previewing the item in that sibling's slot.
    //
    // Guessing a parent by center distance from here returns the element's OWN
    // container, and resolveDropPlacement's container branch appends at its
    // end — a silent no-op that contradicts the preview on screen. A sibling
    // lock we already hold beats a distance guess, so re-resolve it against
    // live DOM rects (which include the preview transform) instead.
    //
    // Containment above still wins, so hovering inside another container
    // enters it exactly as before; only the at-distance guess is preempted.
    // Skipped while a pending cross-container move is active (the item has
    // visually left its source, so source siblings are the wrong candidates)
    // and on source depletion (no source siblings to recover).
    if (
      !options.hasPendingMoveRef.current &&
      hadSiblingHit &&
      sourceItems !== null &&
      sourceItems.size > 0
    ) {
      const recovered = closestCenterLive({
        ...args,
        droppableContainers: sameContainerSiblings,
      })
      if (recovered.length > 0) return captureWinnerNode(recovered)
    }

    // Distance fallback: entering empty containers at distance when pointer
    // is not inside any parent rect (e.g. in the gap between sections).
    return captureWinnerNode(
      closestCenter({
        ...args,
        droppableContainers: parents,
      }),
    )
  }
}
