// @ts-nocheck — TODO(phase-5): rewrite for NodeRef/NodeKey identity model; tracked in plan polished-floating-bubble.md
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import type { DragStartEvent, DragOverEvent, DragEndEvent, DragCancelEvent } from '@dnd-kit/core';
import { useDragAndDrop } from './useDragAndDrop';
import type { UseDragAndDropOptions } from './useDragAndDrop';
import { buildDraggableId } from '@/types/dnd';
import type { ElementTreeResponse } from '@/types/elements';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories';
import { createActive, createOver } from '@/testing/dndHelpers';

beforeEach(() => {
  resetIdCounter();
});

// --- Synthetic event builders ---

function makeDragStartEvent(activeId: string): DragStartEvent {
  return {
    active: createActive(activeId),
    activatorEvent: new Event('pointer'),
  } as unknown as DragStartEvent;
}

function makeDragOverEvent(activeId: string, overId: string | null): DragOverEvent {
  return {
    active: createActive(activeId),
    over: overId !== null ? createOver(overId) : null,
    activatorEvent: new Event('pointer'),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragOverEvent;
}

function makeDragEndEvent(activeId: string, overId: string | null): DragEndEvent {
  return {
    active: createActive(activeId),
    over: overId !== null ? createOver(overId) : null,
    activatorEvent: new Event('pointer'),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragEndEvent;
}

function makeDragCancelEvent(): DragCancelEvent {
  return {
    active: createActive('element-1'),
    activatorEvent: new Event('pointer'),
  } as unknown as DragCancelEvent;
}

// --- Tree builders ---

/**
 * Single column with two leaf elements:
 * Section(10) -> Row(20) -> Column(30) -> [Element(40), Element(41)]
 */
function buildSingleColumnTree(pageId = 1) {
  const element1 = createSimpleElement({ id: 40, parentId: 30 });
  const element2 = createSimpleElement({ id: 41, parentId: 30 });
  const column = createColumnNode({ id: 30, parentId: 20, children: [element1, element2] });
  const row = createRowNode({ id: 20, parentId: 10, children: [column] });
  const section = createSectionNode({ id: 10, parentId: pageId, children: [row] });
  const tree: ElementTreeResponse = { [String(pageId)]: [section] };
  return { tree, element1, element2, column, row, section };
}

/**
 * Two columns under one row, each with one element:
 * Section(10) -> Row(20) -> [Column(30) -> [Element(40)], Column(31) -> [Element(41)]]
 */
function buildTwoColumnTree(pageId = 1) {
  const element1 = createSimpleElement({ id: 40, parentId: 30 });
  const element2 = createSimpleElement({ id: 41, parentId: 31 });
  const col1 = createColumnNode({ id: 30, parentId: 20, children: [element1] });
  const col2 = createColumnNode({ id: 31, parentId: 20, children: [element2] });
  const row = createRowNode({ id: 20, parentId: 10, children: [col1, col2] });
  const section = createSectionNode({ id: 10, parentId: pageId, children: [row] });
  const tree: ElementTreeResponse = { [String(pageId)]: [section] };
  return { tree, element1, element2, col1, col2, row, section };
}

function renderDndHook(options: Partial<UseDragAndDropOptions> = {}) {
  const defaultTree = buildSingleColumnTree().tree;
  const onReorder = vi.fn();
  const mergedOptions: UseDragAndDropOptions = {
    tree: options.tree ?? defaultTree,
    onReorder: options.onReorder ?? onReorder,
  };
  const hookResult = renderHook(() => useDragAndDrop(mergedOptions));
  return { ...hookResult, onReorder };
}

describe('useDragAndDrop', () => {
  describe('initial state', () => {
    it('returns null dragState and null pendingTree', () => {
      const { result } = renderDndHook();

      expect(result.current.dragState).toBeNull();
      expect(result.current.pendingTree).toBeNull();
    });

    it('exposes dndContextProps with handler functions', () => {
      const { result } = renderDndHook();

      expect(typeof result.current.dndContextProps.onDragStart).toBe('function');
      expect(typeof result.current.dndContextProps.onDragOver).toBe('function');
      expect(typeof result.current.dndContextProps.onDragEnd).toBe('function');
      expect(typeof result.current.dndContextProps.onDragCancel).toBe('function');
    });
  });

  describe('onDragStart', () => {
    it('sets dragState with active element info', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });

      expect(result.current.dragState).not.toBeNull();
      expect(result.current.dragState!.activeId).toBe(activeId);
      expect(result.current.dragState!.activeType).toBe('element');
      expect(result.current.dragState!.activeNode.id).toBe(element1.id);
    });

    it('ignores invalid draggable IDs', () => {
      const { tree } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('invalid-id'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('ignores IDs for elements not in the tree', () => {
      const { tree } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      act(() => {
        result.current.dndContextProps.onDragStart(
          makeDragStartEvent(buildDraggableId('element', 999)),
        );
      });

      expect(result.current.dragState).toBeNull();
    });

    it('works for container draggable types', () => {
      const { tree, column } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('column', column.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });

      expect(result.current.dragState).not.toBeNull();
      expect(result.current.dragState!.activeType).toBe('column');
      expect(result.current.dragState!.activeNode.id).toBe(column.id);
    });
  });

  describe('onDragCancel', () => {
    it('clears dragState after a drag start', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent());
      });

      expect(result.current.dragState).toBeNull();
    });

    it('clears pendingTree after a cross-container drag over', () => {
      const { tree, element1, col2 } = buildTwoColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('column', col2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent());
      });

      expect(result.current.pendingTree).toBeNull();
      expect(result.current.dragState).toBeNull();
    });
  });

  describe('onDragOver', () => {
    it('applies pending tree for cross-container move (element over different-type container)', () => {
      const { tree, element1, col2 } = buildTwoColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('column', col2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId));
      });

      expect(result.current.pendingTree).not.toBeNull();
    });

    it('does not apply pending tree for same-container move', () => {
      const { tree, element1, element2 } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('element', element2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId));
      });

      // Same parent (both in column 30) - SortableContext handles visual reorder
      expect(result.current.pendingTree).toBeNull();
    });

    it('ignores over events when over is null', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, null));
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('ignores over events when active.id equals over.id', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, activeId));
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('applies pending tree when dragging element over sibling in different container', () => {
      const { tree, element1, element2 } = buildTwoColumnTree();
      const { result } = renderDndHook({ tree });

      const activeId = buildDraggableId('element', element1.id);
      // element2 is in col2 (parentId=31), element1 is in col1 (parentId=30) -- cross-container
      const overId = buildDraggableId('element', element2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId));
      });

      // Cross-container because parents differ (30 vs 31)
      expect(result.current.pendingTree).not.toBeNull();
    });
  });

  describe('onDragEnd', () => {
    it('clears dragState when over is null', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, null));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('clears dragState when active.id equals over.id (no-op)', () => {
      const { tree, element1 } = buildSingleColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, activeId));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('calls onReorder for same-container reorder', () => {
      const { tree, element1, element2 } = buildSingleColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('element', element2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId));
      });

      // element-40 dropped over element-41 in the same column (30)
      // Same-container logic uses the over element's original index (1),
      // so element-40 moves to index 1 (after element-41)
      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        element1.id, // elementID
        30, // targetParentId (column 30)
        element2.id, // afterElementID (placed after element2)
        expect.any(Function), // clearPendingTree
      );
    });

    it('does not call onReorder when drop resolves to same position (no-op)', () => {
      // Element-40 is already at index 0 in column 30. Dropping it over itself
      // is handled by the active.id === over.id guard, but we can test the
      // resolveReorderParams no-op: same container, same index.
      const element = createSimpleElement({ id: 40, parentId: 30 });
      const column = createColumnNode({ id: 30, parentId: 20, children: [element] });
      const row = createRowNode({ id: 20, parentId: 10, children: [column] });
      const section = createSectionNode({ id: 10, parentId: 1, children: [row] });
      const tree: ElementTreeResponse = { '1': [section] };

      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', 40);
      // Dropping over the column container (cross-type) - the element is the only
      // child, so it stays at afterElementID=null which is its current position
      const overId = buildDraggableId('column', 30);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId));
      });

      // resolveDropPlacement returns a placement, but resolveReorderParams
      // detects it as a no-op (same container, same index) and returns null
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('clears dragState even when onReorder is called', () => {
      const { tree, element1, element2 } = buildSingleColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('element', element2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('handles cross-container drop via pending tree', () => {
      const { tree, element1, col2 } = buildTwoColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      const overContainerId = buildDraggableId('column', col2.id);

      // Start drag and move over the target container to apply pending tree
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId));
      });
      expect(result.current.pendingTree).not.toBeNull();

      // Now drop on element2 (which is in col2) -- the pending tree has element1 already in col2
      const overElementId = buildDraggableId('element', 41);
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overElementId));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      // element-40 moved to col2 (id=31). In the pending tree, element-40 was
      // appended after element-41. The drop on element-41 in same-container
      // logic uses index 0 (element-41's position), placing element-40 before
      // element-41 (afterElementID=null means "insert first").
      expect(onReorder).toHaveBeenCalledWith(
        element1.id,
        31, // targetParentId: col2
        null, // afterElementID: placed first
        expect.any(Function),
      );
      expect(result.current.dragState).toBeNull();
    });

    it('clears pending tree when active ID is unparseable', () => {
      const { tree } = buildSingleColumnTree();
      const onReorder = vi.fn();
      const { result } = renderDndHook({ tree, onReorder });

      // Force a drag state to exist by starting with a valid drag
      const activeId = buildDraggableId('element', 40);
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });

      // End with an unparseable over ID
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, 'garbage'));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('provides clearPendingTree callback to onReorder', () => {
      const { tree, element1, element2 } = buildSingleColumnTree();
      let capturedClear: (() => void) | undefined;
      const onReorder = vi.fn((_id, _parent, _after, clear) => {
        capturedClear = clear;
      });
      const { result } = renderDndHook({ tree, onReorder });

      const activeId = buildDraggableId('element', element1.id);
      const overId = buildDraggableId('element', element2.id);

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId));
      });
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId));
      });

      expect(capturedClear).toBeDefined();
      expect(typeof capturedClear).toBe('function');
    });
  });
});
