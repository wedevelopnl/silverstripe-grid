import {
  closestCenter,
  type CollisionDetection,
  type Collision,
  type DroppableContainer,
} from '@dnd-kit/core';

import { getDraggableType, PARENT_CONTAINER_TYPE } from '@/types/dnd';

/**
 * Like closestCenter, but reads live DOM rects via getBoundingClientRect()
 * instead of using dnd-kit's droppableRects (which are pre-CSS-transform
 * and stale after SortableContext shifts items visually).
 */
const closestCenterLive: CollisionDetection = (args) => {
  const { collisionRect, droppableContainers } = args;
  const centerX = collisionRect.left + collisionRect.width / 2;
  const centerY = collisionRect.top + collisionRect.height / 2;
  const collisions: Collision[] = [];

  for (const container of droppableContainers) {
    const domNode = container.node.current;
    if (!domNode) continue;

    const rect = domNode.getBoundingClientRect();
    const targetCX = rect.left + rect.width / 2;
    const targetCY = rect.top + rect.height / 2;
    const dx = centerX - targetCX;
    const dy = centerY - targetCY;

    collisions.push({
      id: container.id,
      data: { droppableContainer: container, value: dx * dx + dy * dy },
    });
  }

  return collisions.sort(
    (a, b) => (a.data?.value as number) - (b.data?.value as number),
  );
};

/**
 * Filters droppable containers to only those valid for the given active item.
 *
 * A container is valid if:
 * - It has the same type as the active item (sibling reordering)
 * - It has the parent container type of the active item (dropping into parent)
 * - For sections (parent = 'root'), any container with an unparseable ID
 *   (e.g. the root SortableContext whose ID is 'root')
 */
export function filterDroppablesByType(
  activeId: string,
  containers: DroppableContainer[],
): DroppableContainer[] {
  const activeType = getDraggableType(activeId);
  if (activeType === null) return [];

  const parentType = PARENT_CONTAINER_TYPE[activeType];

  return containers.filter((container) => {
    const containerType = getDraggableType(String(container.id));

    if (containerType === activeType) return true;

    if (containerType === parentType) return true;

    // Sections live under the root-level sortable context, whose droppable
    // ID won't parse as a valid draggable type (e.g. the string 'root').
    if (parentType === 'root' && containerType === null) return true;

    return false;
  });
}

/**
 * Returns only same-type sibling containers for the active draggable.
 */
export function filterSiblings(
  activeId: string,
  containers: DroppableContainer[],
): DroppableContainer[] {
  const activeType = getDraggableType(activeId);
  if (activeType === null) return [];

  return containers.filter(
    (container) => getDraggableType(String(container.id)) === activeType,
  );
}

/**
 * Returns only parent-type containers for the active draggable.
 * For sections (parent = 'root'), returns containers with unparseable IDs.
 */
export function filterParentContainers(
  activeId: string,
  containers: DroppableContainer[],
): DroppableContainer[] {
  const activeType = getDraggableType(activeId);
  if (activeType === null) return [];

  const parentType = PARENT_CONTAINER_TYPE[activeType];

  return containers.filter((container) => {
    const containerType = getDraggableType(String(container.id));

    if (parentType === 'root') return containerType === null;

    return containerType === parentType;
  });
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
 * Includes an overlap gate: the collision rect must geometrically
 * intersect the droppable rect. This prevents stale collisions with
 * targets the drag has already passed during cross-container moves.
 */
export const centerCrossing: CollisionDetection = (args) => {
  const { active, collisionRect, droppableContainers, droppableRects } = args;

  const initialRect = active.rect.current.initial;
  if (initialRect === null) return [];

  const initialCX = initialRect.left + initialRect.width / 2;
  const initialCY = initialRect.top + initialRect.height / 2;
  const currentCX = collisionRect.left + collisionRect.width / 2;
  const currentCY = collisionRect.top + collisionRect.height / 2;

  const collisions: Collision[] = [];

  for (const container of droppableContainers) {
    const rect = droppableRects.get(container.id);
    if (rect === undefined) continue;

    // Overlap gate: collision rect must intersect the droppable rect.
    // Non-strict inequalities so touching edges count (adjacent stacked items).
    const overlapsX = collisionRect.left <= rect.left + rect.width && collisionRect.left + collisionRect.width >= rect.left;
    const overlapsY = collisionRect.top <= rect.top + rect.height && collisionRect.top + collisionRect.height >= rect.top;
    if (!overlapsX || !overlapsY) continue;

    const targetCX = rect.left + rect.width / 2;
    const targetCY = rect.top + rect.height / 2;

    // Direction-aware threshold adapts to the collision-rect-to-target
    // size ratio automatically:
    //
    // Dragging TOWARD target from above (downward): use the target's top
    // edge + half collision rect height. When crH ≈ targetH (rows), this
    // approximates the center. When crH << targetH (DragOverlay on
    // sections), this sits near the target edge — reachable by the
    // compact overlay.
    //
    // Dragging TOWARD target from below (upward): use the stricter of
    // the edge-based threshold and the target center. For same-size
    // elements this equals the center (ghost-jump prevention). For
    // mismatched sizes the center is still reachable because upward
    // drags cover the full element gap.
    const thresholdY = initialCY < targetCY
      ? rect.top + collisionRect.height / 2
      : Math.min(rect.top + rect.height - collisionRect.height / 2, targetCY);

    const thresholdX = initialCX < targetCX
      ? rect.left + collisionRect.width / 2
      : Math.min(rect.left + rect.width - collisionRect.width / 2, targetCX);

    // Y-axis crossing: collision rect center crossed the threshold
    const crossedY =
      (initialCY > thresholdY && currentCY <= thresholdY) ||
      (initialCY < thresholdY && currentCY >= thresholdY);

    // X-axis crossing: same logic on horizontal axis
    const crossedX =
      (initialCX > thresholdX && currentCX <= thresholdX) ||
      (initialCX < thresholdX && currentCX >= thresholdX);

    if (crossedY || crossedX) {
      const dx = currentCX - targetCX;
      const dy = currentCY - targetCY;

      collisions.push({
        id: container.id,
        data: { droppableContainer: container, value: dx * dx + dy * dy },
      });
    }
  }

  // Sort by distance to center (closest first)
  return collisions.sort(
    (a, b) => (a.data?.value as number) - (b.data?.value as number),
  );
};

export interface OverRectSnapshot {
  id: string | number;
  rect: { left: number; top: number; width: number; height: number };
}

export interface TypedCollisionDetectionOptions {
  hasPendingMoveRef: { current: boolean };
  /** Set of composite droppable IDs belonging to the active item's pending container. */
  pendingContainerItemsRef?: { current: ReadonlySet<string | number> | null };
  /** Updated on every collision detection cycle with the winning element's rect. */
  overRectRef?: { current: OverRectSnapshot | null };
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
  return (args) => {
    const activeId = String(args.active.id);

    // dnd-kit v6 does not exclude the active item from droppableContainers.
    // Its original-position rect remains registered as a droppable, so
    // closestCenter can return it as the closest target — causing a no-op drop.
    const nonActiveContainers = args.droppableContainers.filter(
      (container) => container.id !== args.active.id,
    );

    /**
     * Capture the winning collision's live DOM rect via getBoundingClientRect().
     * Both `over.rect` (from DragEndEvent) and `droppableRects` (from the
     * measuring system) can be stale after cross-container re-renders shift
     * element positions — dnd-kit only re-measures when droppable IDs change,
     * not when existing elements move. Reading the DOM directly ensures the
     * direction comparison in handleDragEnd uses the element's actual position.
     */
    const captureWinnerRect = (collisions: Collision[]) => {
      if (options.overRectRef && collisions.length > 0) {
        const winnerId = collisions[0].id;
        const container = args.droppableContainers.find((c) => c.id === winnerId);
        const domNode = container?.node.current;
        if (domNode) {
          const domRect = domNode.getBoundingClientRect();
          options.overRectRef.current = {
            id: winnerId,
            rect: { left: domRect.left, top: domRect.top, width: domRect.width, height: domRect.height },
          };
        }
      }
      return collisions;
    };

    const siblings = filterSiblings(activeId, nonActiveContainers);

    if (options.hasPendingMoveRef.current) {
      // After a pending cross-container move, SortableContext CSS transforms
      // shift items visually, but droppableRects reflect pre-transform DOM
      // positions. closestCenter uses these stale rects and picks the wrong
      // element. Instead, read live DOM rects via getBoundingClientRect()
      // which includes CSS transforms.
      //
      // Also filter to only siblings in the pending container to prevent
      // wrong-container bouncing.
      const pendingItems = options.pendingContainerItemsRef?.current;
      const pendingSiblings = pendingItems !== null && pendingItems !== undefined
        ? siblings.filter((s) => pendingItems.has(s.id))
        : siblings;

      const siblingCollisions = closestCenterLive({
        ...args,
        droppableContainers: pendingSiblings,
      });

      if (siblingCollisions.length > 0) {
        return captureWinnerRect(siblingCollisions);
      }
    } else {
      // Pass 1: prefer sibling collisions — centerCrossing requires the dragged
      // item's center to cross the midpoint threshold, preventing ghost jumps when
      // only 2 siblings exist at close proximity. The overlap gate ensures stale
      // source-container collisions don't persist during cross-container drags.
      const siblingCollisions = centerCrossing({
        ...args,
        droppableContainers: siblings,
      });

      if (siblingCollisions.length > 0) {
        return captureWinnerRect(siblingCollisions);
      }

      // Guard: if the collision rect overlaps any sibling (but the crossing
      // threshold wasn't met), return empty rather than falling through to
      // parent containers. Returning a parent container as `over` causes
      // SortableContext's sorting strategy to produce incorrect transforms
      // (the parent ID isn't in the items array, resulting in overIndex = -1).
      const { collisionRect, droppableRects } = args;
      const overlapsSibling = siblings.some((sibling) => {
        const rect = droppableRects.get(sibling.id);
        if (rect === undefined) return false;

        return (
          collisionRect.left <= rect.left + rect.width &&
          collisionRect.left + collisionRect.width >= rect.left &&
          collisionRect.top <= rect.top + rect.height &&
          collisionRect.top + collisionRect.height >= rect.top
        );
      });

      if (overlapsSibling) {
        return [];
      }
    }

    // Pass 2: fall back to parent container collisions (closestCenter so
    // entering empty containers triggers at distance)
    const parents = filterParentContainers(activeId, nonActiveContainers);

    return captureWinnerRect(closestCenter({
      ...args,
      droppableContainers: parents,
    }));
  };
}

/**
 * Static type-aware collision detection — convenience wrapper using
 * `hasPendingMove = false` (always uses centerCrossing for siblings).
 */
export const typedCollisionDetection: CollisionDetection =
  createTypedCollisionDetection({ hasPendingMoveRef: { current: false } });
