import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import { PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import type {
  CollisionDetection,
  DragCancelEvent,
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
  SensorDescriptor,
  SensorOptions,
} from '@dnd-kit/core';
import { buildDraggableId, parseDraggableId } from '@/types/dnd';
import type { DraggableType } from '@/types/dnd';
import { isContainerNode } from '@/types/elements';
import type { ElementNode, ElementTreeResponse } from '@/types/elements';
import { useElementMaps } from '@/hooks/useElementMaps';
import { usePendingTree } from '@/hooks/usePendingTree';
import { resolveDropPlacement } from '@/utils/resolveDropPlacement';
import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import { createTypedCollisionDetection } from '@/utils/collisionDetection';

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

export interface DndContextProps {
  sensors: SensorDescriptor<SensorOptions>[];
  collisionDetection: CollisionDetection;
  onDragStart: (event: DragStartEvent) => void;
  onDragOver: (event: DragOverEvent) => void;
  onDragEnd: (event: DragEndEvent) => void;
  onDragCancel: (event: DragCancelEvent) => void;
}

export interface UseDragAndDropReturn {
  /** Spread directly onto DndContext. */
  dndContextProps: DndContextProps;
  /** Current drag state for DragOverlay rendering. */
  dragState: DragState | null;
  /** Tree with pending cross-container move applied, or null. */
  pendingTree: ElementTreeResponse | null;
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
  active: {
    rect: {
      current: {
        initial: { left: number; top: number } | null;
        translated: { left: number; top: number } | null;
      };
    };
  };
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

export function useDragAndDrop({ tree, onReorder }: UseDragAndDropOptions): UseDragAndDropReturn {
  const [dragState, setDragState] = useState<DragState | null>(null);
  const maps = useElementMaps(tree);
  const pending = usePendingTree();

  const [collisionDetection] = useState<CollisionDetection>(() =>
    createTypedCollisionDetection(pending.collisionRefs),
  );

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  );

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const parsed = parseDraggableId(String(event.active.id));
      if (!parsed) return;

      const node = maps.nodeMap.get(parsed.id);
      if (!node) return;

      const siblings = maps.childrenByParentId.get(node.parentId) ?? [];
      pending.setSourceSiblings(
        new Set(
          siblings
            .filter((n) => n.id !== parsed.id)
            .map((n) => buildDraggableId(parsed.type, n.id)),
        ),
      );

      setDragState({
        activeId: String(event.active.id),
        activeType: parsed.type,
        activeNode: node,
      });
    },
    [maps, pending],
  );

  const handleDragOver = useCallback(
    (event: DragOverEvent) => {
      const { active, over } = event;
      if (!over || active.id === over.id) return;

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) return;

      const { tree: effectiveTree, maps: effectiveMaps } = pending.getEffective(tree, maps);

      const activeNode = effectiveMaps.nodeMap.get(activeParsed.id);
      if (!activeNode) return;

      let targetParentId: number;
      let afterElementId: number | null;

      if (overParsed.type === activeParsed.type) {
        const overNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!overNode) return;
        targetParentId = overNode.parentId;

        const pointer = getPointerPosition(event);
        if (
          pointer !== null &&
          resolveInsertDirection(pointer, over.rect, activeParsed.type) === 'before'
        ) {
          const siblings = effectiveMaps.childrenByParentId.get(targetParentId) ?? [];
          const overIdx = siblings.findIndex((n) => n.id === overParsed.id);
          afterElementId = overIdx > 0 ? siblings[overIdx - 1].id : null;
        } else {
          afterElementId = overParsed.id;
        }
      } else {
        const containerNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) return;
        targetParentId = containerNode.id;
        const children = containerNode.children ?? [];
        afterElementId = children.length > 0 ? children[children.length - 1].id : null;
      }

      // Same-container: SortableContext handles visual reordering via transforms
      if (activeNode.parentId === targetParentId) return;

      pending.applyPendingMove(activeParsed, targetParentId, afterElementId, effectiveTree);
    },
    [tree, maps, pending],
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      setDragState(null);

      const { active, over } = event;
      if (!over || active.id === over.id) {
        pending.clear();
        return;
      }

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) {
        pending.clear();
        return;
      }

      const activeNode = maps.nodeMap.get(activeParsed.id);
      if (!activeNode) {
        pending.clear();
        return;
      }

      const sourceParentId = activeNode.parentId;
      const sourceChildren = maps.childrenByParentId.get(sourceParentId);
      if (!sourceChildren) {
        pending.clear();
        return;
      }
      const sourceIndex = sourceChildren.findIndex((n) => n.id === activeParsed.id);

      const { maps: effectiveMaps } = pending.getEffective(tree, maps);

      // Read the over element's live DOM rect at drop time. Both pointer and
      // getBoundingClientRect() are in viewport space (including SortableContext
      // CSS transforms), so comparing them gives the correct before/after
      // direction. Using a cached rect from collision detection or over.rect
      // (pre-transform from dnd-kit) can produce wrong directions when
      // SortableContext transforms shift the element between capture and drop.
      const overSnapshot = pending.collisionRefs.overRectRef.current;
      const overNode = overSnapshot?.nodeRef.current;
      const effectiveOverRect =
        String(overSnapshot?.id) === String(over.id) && overNode
          ? overNode.getBoundingClientRect()
          : over.rect;

      const pointer = getPointerPosition(event);

      const placement = resolveDropPlacement({
        activeParsed,
        overParsed,
        pointer,
        maps: effectiveMaps,
        sourceParentId,
        sourceIndex,
        overRect: effectiveOverRect,
      });

      if (placement) {
        onReorder(
          placement.elementID,
          placement.targetParentId,
          placement.afterElementID,
          pending.clear,
        );
      } else {
        pending.clear();
      }
    },
    [tree, maps, onReorder, pending],
  );

  const handleDragCancel = useCallback(() => {
    setDragState(null);
    pending.clear();
  }, [pending]);

  const dndContextProps = useMemo<DndContextProps>(
    () => ({
      sensors,
      collisionDetection,
      onDragStart: handleDragStart,
      onDragOver: handleDragOver,
      onDragEnd: handleDragEnd,
      onDragCancel: handleDragCancel,
    }),
    [sensors, collisionDetection, handleDragStart, handleDragOver, handleDragEnd, handleDragCancel],
  );

  return {
    dndContextProps,
    dragState,
    pendingTree: pending.pendingTree,
  };
}
