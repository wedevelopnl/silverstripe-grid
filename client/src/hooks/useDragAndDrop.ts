import { createContext, useCallback, useContext, useRef, useState } from 'react';
import {
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import type {
  CollisionDetection,
  DragCancelEvent,
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
  SensorDescriptor,
  SensorOptions,
} from '@dnd-kit/core';
import {
  buildDraggableId,
  parseDraggableId,
} from '@/types/dnd';
import type { DraggableType } from '@/types/dnd';
import { isContainerNode } from '@/types/elements';
import type {
  ElementNode,
  ElementTreeResponse,
} from '@/types/elements';
import { useElementMaps, buildMaps } from '@/hooks/useElementMaps';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { resolveReorderParams } from '@/utils/resolveReorderParams';
import { applyReorder } from '@/utils/applyReorder';
import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import { createTypedCollisionDetection } from '@/utils/collisionDetection';
import type { OverRectSnapshot } from '@/utils/collisionDetection';

// --- Public types ---

export interface DragState {
  activeId: string;
  activeType: DraggableType;
  activeNode: ElementNode;
}

export interface UseDragAndDropOptions {
  tree: ElementTreeResponse;
  onReorder: (
    elementID: number,
    targetParentId: number,
    afterElementID: number | null,
    clearPendingTree: () => void,
  ) => void;
}

export interface UseDragAndDropReturn {
  sensors: SensorDescriptor<SensorOptions>[];
  collisionDetection: CollisionDetection;
  dragState: DragState | null;
  /** Tree with any pending cross-container move applied, or null if no move in progress. */
  pendingTree: ElementTreeResponse | null;
  handleDragStart: (event: DragStartEvent) => void;
  handleDragOver: (event: DragOverEvent) => void;
  handleDragEnd: (event: DragEndEvent) => void;
  handleDragCancel: (event: DragCancelEvent) => void;
}

// --- Drag context ---

export interface DragContextValue {
  activeType: DraggableType | null;
}

export const DragContext = createContext<DragContextValue>({ activeType: null });

export function useDragContext(): DragContextValue {
  return useContext(DragContext);
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
  activatorEvent: Event;
  active: { rect: { current: { initial: { left: number; top: number } | null; translated: { left: number; top: number } | null } } };
}): { x: number; y: number } | null {
  const pe = event.activatorEvent;
  if (!(pe instanceof PointerEvent)) return null;

  const initialRect = event.active.rect.current.initial;
  const translated = event.active.rect.current.translated;
  if (!initialRect || !translated) return null;

  return {
    x: translated.left + (pe.clientX - initialRect.left),
    y: translated.top + (pe.clientY - initialRect.top),
  };
}

// --- Hook ---

const POINTER_DISTANCE_THRESHOLD = 8;

export function useDragAndDrop({
  tree,
  onReorder,
}: UseDragAndDropOptions): UseDragAndDropReturn {
  const [dragState, setDragState] = useState<DragState | null>(null);
  const [pendingTree, setPendingTree] = useState<ElementTreeResponse | null>(null);
  const pendingTreeRef = useRef<ElementTreeResponse | null>(null);
  const hasPendingMoveRef = useRef(false);
  const overRectRef = useRef<OverRectSnapshot | null>(null);
  const pendingContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null);
  const pendingMapsRef = useRef<ElementMaps | null>(null);
  const sourceContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null);
  const maps = useElementMaps(tree);

  // Stable collision detection instance — refs are read dynamically per event
  const [collisionDetection] = useState<CollisionDetection>(
    () => createTypedCollisionDetection({ hasPendingMoveRef, pendingContainerItemsRef, sourceContainerItemsRef, overRectRef }),
  );

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  );

  const clearPendingTree = useCallback(() => {
    pendingTreeRef.current = null;
    pendingMapsRef.current = null;
    hasPendingMoveRef.current = false;
    overRectRef.current = null;
    pendingContainerItemsRef.current = null;
    sourceContainerItemsRef.current = null;
    setPendingTree(null);
  }, []);

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const parsed = parseDraggableId(String(event.active.id));
      if (!parsed) return;

      const node = maps.nodeMap.get(parsed.id);
      if (!node) return;

      // Track source container siblings so the guard in collision detection
      // only blocks parent-container fallback when the pointer is inside a
      // same-container sibling, not when entering a different container.
      const siblings = maps.childrenByParentId.get(node.parentId) ?? [];
      sourceContainerItemsRef.current = new Set(
        siblings
          .filter((n) => n.id !== parsed.id)
          .map((n) => buildDraggableId(parsed.type, n.id)),
      );

      setDragState({
        activeId: String(event.active.id),
        activeType: parsed.type,
        activeNode: node,
      });
    },
    [maps],
  );

  const handleDragOver = useCallback(
    (event: DragOverEvent) => {
      const { active, over } = event;
      if (!over || active.id === over.id) return;

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) return;

      // Use the effective tree (with any existing pending move applied)
      const effectiveTree = pendingTreeRef.current ?? tree;
      const effectiveMaps = pendingMapsRef.current ?? maps;

      const activeNode = effectiveMaps.nodeMap.get(activeParsed.id);
      if (!activeNode) return;

      let targetParentId: number;
      let afterElementId: number | null;

      if (overParsed.type === activeParsed.type) {
        // Over a sibling — use the sibling's parent
        const overNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!overNode) return;
        targetParentId = overNode.parentId;

        // Direction-aware: place before or after based on pointer vs over center.
        const pointer = getPointerPosition(event);
        if (pointer !== null && resolveInsertDirection(pointer, over.rect, activeParsed.type) === 'before') {
          const siblings = effectiveMaps.childrenByParentId.get(targetParentId) ?? [];
          const overIdx = siblings.findIndex((n) => n.id === overParsed.id);
          afterElementId = overIdx > 0 ? siblings[overIdx - 1].id : null;
        } else {
          afterElementId = overParsed.id;
        }
      } else {
        // Over a container — append to end
        const containerNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) return;
        targetParentId = containerNode.id;
        const children = containerNode.children ?? [];
        afterElementId = children.length > 0 ? children[children.length - 1].id : null;
      }

      // Same-container: SortableContext handles visual reordering via transforms.
      // handleDragEnd computes the final position from the pointer direction,
      // so no ref correction is needed here.
      if (activeNode.parentId === targetParentId) {
        return;
      }

      const newTree = applyReorder(effectiveTree, activeParsed.id, targetParentId, afterElementId);
      if (newTree !== effectiveTree) {
        const newMaps = buildMaps(newTree);
        pendingTreeRef.current = newTree;
        pendingMapsRef.current = newMaps;
        hasPendingMoveRef.current = true;

        // Compute the set of sibling droppable IDs in the target container
        // so collision detection can filter out wrong-container siblings
        // whose stale rects would cause closestCenter to bounce the item back.
        const targetSiblings = newMaps.childrenByParentId.get(targetParentId) ?? [];
        pendingContainerItemsRef.current = new Set(
          targetSiblings.map((n) => buildDraggableId(activeParsed.type, n.id)),
        );

        setPendingTree(newTree);
      }
    },
    [tree],
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      const currentPendingTree = pendingTreeRef.current;
      const currentOverRect = overRectRef.current;
      const pointer = getPointerPosition(event);

      setDragState(null);

      const { active, over } = event;

      if (!over || active.id === over.id) {
        clearPendingTree();
        return;
      }

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) {
        clearPendingTree();
        return;
      }

      const activeNode = maps.nodeMap.get(activeParsed.id);
      if (!activeNode) {
        clearPendingTree();
        return;
      }

      const sourceParentId = activeNode.parentId;
      const sourceChildren = maps.childrenByParentId.get(sourceParentId);
      if (!sourceChildren) {
        clearPendingTree();
        return;
      }
      const sourceIndex = sourceChildren.findIndex((n) => n.id === activeParsed.id);

      // Cross-container drag: the pending tree knows which container the item
      // is in, but the final position within that container is computed from
      // the pointer direction relative to the 'over' element's DOM rect.
      // This avoids reliance on ref-based corrections that can diverge from
      // visual DOM positions during cascading within-container events.
      if (currentPendingTree !== null) {
        const pendingMaps = buildMaps(currentPendingTree);
        const pendingNode = pendingMaps.nodeMap.get(activeParsed.id);
        if (pendingNode) {
          const targetParentId = pendingNode.parentId;
          const siblings = pendingMaps.childrenByParentId.get(targetParentId) ?? [];

          // Build the sibling list without the active item — this is the
          // baseline order into which we'll insert at the pointer position.
          const compositeIds = siblings.map((n) => buildDraggableId(activeParsed.type, n.id));
          const filtered = compositeIds.filter((id) => id !== String(active.id));

          let insertIndex: number;

          if (overParsed.type === activeParsed.type) {
            // Over a sibling — find its position in the filtered list
            // (excluding the active item) and use pointer direction.
            const overCompositeId = buildDraggableId(overParsed.type, overParsed.id);
            const overIdx = filtered.indexOf(overCompositeId);
            if (overIdx === -1) {
              // Over element not in this container — append to end
              insertIndex = filtered.length;
            } else {
              insertIndex = overIdx;

              // Use the over element's fresh DOM rect captured during collision
              // detection. `over.rect` from DragEndEvent can be stale after
              // cross-container re-renders shift droppable positions.
              const freshOverRect = String(currentOverRect?.id) === String(over.id)
                ? currentOverRect!.rect
                : over.rect;

              if (pointer !== null && resolveInsertDirection(pointer, freshOverRect, activeParsed.type) === 'after') {
                insertIndex += 1;
              }
            }
          } else {
            // Over a container — append to end
            insertIndex = filtered.length;
          }

          const clampedIndex = Math.min(insertIndex, filtered.length);
          filtered.splice(clampedIndex, 0, String(active.id));

          const params = resolveReorderParams({
            activeId: String(active.id),
            overContainerParentId: targetParentId,
            overIndex: filtered.indexOf(String(active.id)),
            containerItems: filtered,
            sourceContainerParentId: sourceParentId,
            sourceIndex,
          });

          if (params) {
            onReorder(params.elementID, params.targetParentId, params.afterElementID, clearPendingTree);
          } else {
            clearPendingTree();
          }
          return;
        }
      }

      // Same-container reorder (no pending tree): original logic
      let targetParentId: number;
      let containerChildren: ElementNode[];
      let insertIndex: number;

      if (overParsed.type === activeParsed.type) {
        // Over a sibling item — use the sibling's parentId
        const overNode = maps.nodeMap.get(overParsed.id);
        if (!overNode) {
          clearPendingTree();
          return;
        }

        targetParentId = overNode.parentId;
        const targetChildren = maps.childrenByParentId.get(targetParentId);
        if (!targetChildren) {
          clearPendingTree();
          return;
        }

        containerChildren = targetChildren;
        insertIndex = targetChildren.findIndex((n) => n.id === overParsed.id);

        // Fallback direction check for cross-container drops without a pending tree
        // (e.g., very fast drag where handleDragOver didn't fire)
        if (sourceParentId !== targetParentId && pointer !== null) {
          const freshOverRect = String(currentOverRect?.id) === String(over.id)
            ? currentOverRect!.rect
            : over.rect;
          if (resolveInsertDirection(pointer, freshOverRect, activeParsed.type) === 'after') {
            insertIndex += 1;
          }
        }
      } else {
        // Over a container — drop into it (container's own ID is the parent)
        const containerNode = maps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) {
          clearPendingTree();
          return;
        }

        targetParentId = containerNode.id;
        containerChildren = containerNode.children ?? [];
        insertIndex = containerChildren.length;
      }

      // Build the ordered ID list with the active item placed at the target position
      const compositeIds = containerChildren.map((n) =>
        buildDraggableId(activeParsed.type, n.id),
      );
      const filtered = compositeIds.filter((id) => id !== String(active.id));
      const clampedIndex = Math.min(insertIndex, filtered.length);
      filtered.splice(clampedIndex, 0, String(active.id));

      const resolveContext = {
        activeId: String(active.id),
        overContainerParentId: targetParentId,
        overIndex: filtered.indexOf(String(active.id)),
        containerItems: filtered,
        sourceContainerParentId: sourceParentId,
        sourceIndex,
      };

      const params = resolveReorderParams(resolveContext);

      if (params) {
        onReorder(params.elementID, params.targetParentId, params.afterElementID, clearPendingTree);
      } else {
        clearPendingTree();
      }
    },
    [maps, onReorder, clearPendingTree],
  );

  const handleDragCancel = useCallback(() => {
    setDragState(null);
    clearPendingTree();
  }, [clearPendingTree]);

  return {
    sensors,
    collisionDetection,
    dragState,
    pendingTree,
    handleDragStart,
    handleDragOver,
    handleDragEnd,
    handleDragCancel,
  };
}
