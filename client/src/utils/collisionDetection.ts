import {
  closestCenter,
  pointerWithin,
  type CollisionDetection,
  type DroppableContainer,
} from '@dnd-kit/core';

import { getDraggableType, PARENT_CONTAINER_TYPE } from '@/types/dnd';

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
 * Type-aware collision detection strategy for dnd-kit.
 *
 * Uses a two-pass approach to prevent oscillation between sibling items and
 * their wrapping parent container (which geometrically encloses its children):
 *
 * 1. Run pointerWithin against same-type siblings only (requires pointer inside target)
 * 2. If no sibling collision, fall back to closestCenter against parent containers
 *
 * This ensures sibling reordering always wins over container drops when both
 * are in range, while cross-container moves (into empty containers) still work.
 */
export const typedCollisionDetection: CollisionDetection = (args) => {
  const activeId = String(args.active.id);

  // dnd-kit v6 does not exclude the active item from droppableContainers.
  // Its original-position rect remains registered as a droppable, so
  // closestCenter can return it as the closest target — causing a no-op drop.
  const nonActiveContainers = args.droppableContainers.filter(
    (container) => container.id !== args.active.id,
  );

  // Pass 1: prefer sibling collisions — pointerWithin requires the pointer
  // to be geometrically inside the target rect, preventing ghost jumps when
  // only 2 siblings exist at close proximity.
  const siblings = filterSiblings(activeId, nonActiveContainers);
  const siblingCollisions = pointerWithin({
    ...args,
    droppableContainers: siblings,
  });

  if (siblingCollisions.length > 0) {
    return siblingCollisions;
  }

  // Pass 2: fall back to parent container collisions (closestCenter so
  // entering empty containers triggers at distance)
  const parents = filterParentContainers(activeId, nonActiveContainers);

  return closestCenter({
    ...args,
    droppableContainers: parents,
  });
};
