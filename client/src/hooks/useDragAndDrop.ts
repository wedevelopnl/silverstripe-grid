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
import { resolveReorderParams } from '@/utils/resolveReorderParams';
import { applyReorder } from '@/utils/applyReorder';
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
  const maps = useElementMaps(tree);

  // Stable collision detection instance — the ref is read dynamically per event
  const [collisionDetection] = useState<CollisionDetection>(
    () => createTypedCollisionDetection({ hasPendingMoveRef }),
  );

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  );

  const clearPendingTree = useCallback(() => {
    pendingTreeRef.current = null;
    hasPendingMoveRef.current = false;
    setPendingTree(null);
  }, []);

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const parsed = parseDraggableId(String(event.active.id));
      if (!parsed) return;

      const node = maps.nodeMap.get(parsed.id);
      if (!node) return;

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
      const effectiveMaps = buildMaps(effectiveTree);

      const activeNode = effectiveMaps.nodeMap.get(activeParsed.id);
      if (!activeNode) return;

      let targetParentId: number;
      let afterElementId: number | null;

      if (overParsed.type === activeParsed.type) {
        // Over a sibling — use the sibling's parent
        const overNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!overNode) return;
        targetParentId = overNode.parentId;
        afterElementId = overParsed.id;
      } else {
        // Over a container — append to end
        const containerNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) return;
        targetParentId = containerNode.id;
        const children = containerNode.children ?? [];
        afterElementId = children.length > 0 ? children[children.length - 1].id : null;
      }

      // Same-container: SortableContext handles visual reordering via transforms.
      // During a cross-container drag, also track within-container position
      // in the ref (not state) so handleDragEnd can read the correct final position.
      if (activeNode.parentId === targetParentId) {
        if (pendingTreeRef.current !== null && overParsed.type === activeParsed.type) {
          const containerChildren = effectiveMaps.childrenByParentId.get(targetParentId) ?? [];
          const activeIdx = containerChildren.findIndex((n) => n.id === activeParsed.id);
          const overIdx = containerChildren.findIndex((n) => n.id === overParsed.id);

          // SortableContext places active at overIdx: when activeIdx > overIdx
          // the active moves UP (before over), otherwise DOWN (after over).
          const correctedAfterId = activeIdx > overIdx
            ? (overIdx > 0 ? containerChildren[overIdx - 1].id : null)
            : overParsed.id;

          const newTree = applyReorder(effectiveTree, activeParsed.id, targetParentId, correctedAfterId);
          if (newTree !== effectiveTree) {
            pendingTreeRef.current = newTree;
          }
        }
        return;
      }

      const newTree = applyReorder(effectiveTree, activeParsed.id, targetParentId, afterElementId);
      if (newTree !== effectiveTree) {
        pendingTreeRef.current = newTree;
        hasPendingMoveRef.current = true;
        setPendingTree(newTree);
      }
    },
    [tree],
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      // Capture pending tree before clearing — used for cross-container position resolution
      const currentPendingTree = pendingTreeRef.current;

      setDragState(null);
      clearPendingTree();

      const { active, over } = event;

      if (!over || active.id === over.id) {
        return;
      }

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) {
        return;
      }

      const activeNode = maps.nodeMap.get(activeParsed.id);
      if (!activeNode) {
        return;
      }

      const sourceParentId = activeNode.parentId;
      const sourceChildren = maps.childrenByParentId.get(sourceParentId);
      if (!sourceChildren) {
        return;
      }
      const sourceIndex = sourceChildren.findIndex((n) => n.id === activeParsed.id);

      // Cross-container drag: the pending tree tracks the active item's position
      // through both cross-container moves and within-container reordering.
      // Read the final position directly from the pending tree.
      if (currentPendingTree !== null) {
        const pendingMaps = buildMaps(currentPendingTree);
        const pendingNode = pendingMaps.nodeMap.get(activeParsed.id);
        if (pendingNode) {
          const targetParentId = pendingNode.parentId;
          const siblings = pendingMaps.childrenByParentId.get(targetParentId) ?? [];
          const idx = siblings.findIndex((n) => n.id === activeParsed.id);
          const compositeIds = siblings.map((n) => buildDraggableId(activeParsed.type, n.id));

          const params = resolveReorderParams({
            activeId: String(active.id),
            overContainerParentId: targetParentId,
            overIndex: idx,
            containerItems: compositeIds,
            sourceContainerParentId: sourceParentId,
            sourceIndex,
          });

          if (params) {
            onReorder(params.elementID, params.targetParentId, params.afterElementID);
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
          return;
        }

        targetParentId = overNode.parentId;
        const targetChildren = maps.childrenByParentId.get(targetParentId);
        if (!targetChildren) {
          return;
        }

        containerChildren = targetChildren;
        insertIndex = targetChildren.findIndex((n) => n.id === overParsed.id);

        // Fallback direction check for cross-container drops without a pending tree
        // (e.g., very fast drag where handleDragOver didn't fire)
        if (sourceParentId !== targetParentId) {
          const translated = active.rect.current.translated;
          if (translated !== null) {
            const dragCenterY = translated.top + translated.height / 2;
            const overCenterY = over.rect.top + over.rect.height / 2;
            if (dragCenterY > overCenterY) {
              insertIndex += 1;
            }
          }
        }
      } else {
        // Over a container — drop into it (container's own ID is the parent)
        const containerNode = maps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) {
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
        onReorder(params.elementID, params.targetParentId, params.afterElementID);
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
