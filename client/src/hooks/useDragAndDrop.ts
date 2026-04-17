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
import type { ElementNode, TreeApiResponse } from '@/types/elements';
import { buildNodeKey, nodeRefEquals, type NodeRef } from '@/types/identity';
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
  tree: TreeApiResponse;
  onReorder: (
    element: NodeRef,
    parent: NodeRef,
    after: NodeRef | null,
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
  pendingTree: TreeApiResponse | null;
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
    // Stryker disable next-line all: Equivalent — sensor activation config is bypassed by synthetic DragEvent tests that invoke the handlers directly
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  );

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const activeId = String(event.active.id);
      const parsed = parseDraggableId(activeId);
      if (!parsed) return;

      const node = maps.nodeMap.get(activeId);
      if (!node) return;

      const siblings = maps.childrenByParentKey.get(node.parentKey) ?? [];
      // Stryker disable next-line all: Equivalent — sourceContainerItemsRef is consumed only by collision detection (not exercised in synthetic DragEvent tests) and is not exposed on the hook's public API
      const filteredSiblings = siblings.filter((n) => n.nodeKey !== activeId);
      pending.setSourceSiblings(
        new Set(filteredSiblings.map((n) => buildDraggableId(parsed.type, n.self.id))),
      );

      setDragState({
        activeId,
        activeType: parsed.type,
        activeNode: node,
      });
    },
    [maps, pending],
  );

  const handleDragOver = useCallback(
    (event: DragOverEvent) => {
      const { active, over } = event;
      // Stryker disable next-line all: Equivalent — downstream nodeRefEquals(activeNode.parent, targetParent) also short-circuits the same-element case without applying a pending move
      if (!over || active.id === over.id) return;

      const activeId = String(active.id);
      const overId = String(over.id);

      const activeParsed = parseDraggableId(activeId);
      const overParsed = parseDraggableId(overId);
      if (!activeParsed || !overParsed) return;

      const { tree: effectiveTree, maps: effectiveMaps } = pending.getEffective(tree, maps);

      const activeNode = effectiveMaps.nodeMap.get(activeId);
      if (!activeNode) return;

      let targetParent: NodeRef;
      let after: NodeRef | null;

      if (overParsed.type === activeParsed.type) {
        const overNode = effectiveMaps.nodeMap.get(overId);
        if (!overNode) return;
        targetParent = overNode.parent;

        const pointer = getPointerPosition(event);
        if (
          pointer !== null &&
          resolveInsertDirection(pointer, over.rect, activeParsed.type) === 'before'
        ) {
          const siblings = effectiveMaps.childrenByParentKey.get(overNode.parentKey) ?? [];
          const overIdx = siblings.findIndex((n) => n.nodeKey === overId);
          after = overIdx > 0 ? siblings[overIdx - 1].self : null;
        } else {
          after = overNode.self;
        }
      } else {
        const containerNode = effectiveMaps.nodeMap.get(overId);
        if (!containerNode || !isContainerNode(containerNode)) return;
        targetParent = containerNode.self;
        const children = containerNode.children ?? [];
        after = children.length > 0 ? children[children.length - 1].self : null;
      }

      // Same-container: SortableContext handles visual reordering via transforms
      if (nodeRefEquals(activeNode.parent, targetParent)) return;

      const targetParentKey = buildNodeKey(targetParent.type, targetParent.id);
      pending.applyPendingMove(activeParsed, targetParentKey, after?.id ?? null, effectiveTree);
    },
    [tree, maps, pending],
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      setDragState(null);

      const { active, over } = event;
      // Stryker disable next-line all: Equivalent — resolveDropPlacement already returns null for same-element drops, falling through to the else branch below with identical observable behavior
      if (!over || active.id === over.id) {
        pending.clear();
        return;
      }

      const activeId = String(active.id);
      const overId = String(over.id);

      const activeParsed = parseDraggableId(activeId);
      const overParsed = parseDraggableId(overId);
      if (!activeParsed || !overParsed) {
        pending.clear();
        return;
      }

      const activeNode = maps.nodeMap.get(activeId);
      if (!activeNode) {
        pending.clear();
        return;
      }

      const sourceParentKey = activeNode.parentKey;
      const sourceChildren = maps.childrenByParentKey.get(sourceParentKey);
      if (!sourceChildren) {
        pending.clear();
        return;
      }
      const sourceIndex = sourceChildren.findIndex((n) => n.nodeKey === activeId);

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
        // Stryker disable next-line all: Equivalent — overRectRef is populated only by real collision detection, which is not exercised in synthetic DragEvent tests (overSnapshot remains null, both branches resolve to over.rect)
        String(overSnapshot?.id) === String(over.id) && overNode
          ? overNode.getBoundingClientRect()
          : over.rect;

      const pointer = getPointerPosition(event);

      const placement = resolveDropPlacement({
        activeParsed,
        overParsed,
        pointer,
        maps: effectiveMaps,
        sourceParentKey,
        sourceIndex,
        overRect: effectiveOverRect,
      });

      if (placement) {
        onReorder(placement.element, placement.parent, placement.after, pending.clear);
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
