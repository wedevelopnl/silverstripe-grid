import { renderHook, act } from '@testing-library/react';
import type {
  DragStartEvent,
  DragEndEvent,
  DragOverEvent,
  DragCancelEvent,
  Active,
  Over,
} from '@dnd-kit/core';
import type { MutableRefObject } from 'react';

// jsdom does not implement PointerEvent — polyfill with MouseEvent
if (typeof globalThis.PointerEvent === 'undefined') {
  (globalThis as Record<string, unknown>).PointerEvent = class PointerEvent extends MouseEvent {
    constructor(type: string, init?: PointerEventInit) {
      super(type, init);
    }
  };
}
import type { ElementTreeResponse } from '@/types/elements';
import { useDragAndDrop } from '@/hooks/useDragAndDrop';
import type { DragState } from '@/hooks/useDragAndDrop';
import { makeElement, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

// --- dnd-kit event factories (simplified — no rect geometry needed) ---

function createMutableRef<T>(value: T): MutableRefObject<T> {
  return { current: value };
}

function makeActive(id: string): Active {
  return {
    id,
    data: createMutableRef(undefined),
    rect: createMutableRef({ initial: null, translated: null }),
  };
}

function makeOver(id: string): Over {
  return {
    id,
    data: createMutableRef(undefined),
    rect: { width: 0, height: 0, top: 0, left: 0, right: 0, bottom: 0 },
    disabled: false,
  };
}

function makeDragStartEvent(activeId: string): DragStartEvent {
  return {
    active: makeActive(activeId),
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragEndEvent(activeId: string, overId: string | null): DragEndEvent {
  return {
    active: makeActive(activeId),
    over: overId !== null ? makeOver(overId) : null,
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragOverEvent(activeId: string, overId: string): DragOverEvent {
  return {
    active: makeActive(activeId),
    over: makeOver(overId),
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragCancelEvent(activeId: string): DragCancelEvent {
  return {
    active: makeActive(activeId),
    over: null,
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

// --- Test trees ---

const testTree: ElementTreeResponse = {
  '42': [
    makeSection(1, [
      makeRow(10, [
        makeColumn(20, [makeElement(30, 20), makeElement(31, 20)], 10),
        makeColumn(21, [makeElement(32, 21)], 10),
      ], 1),
    ], 42),
  ],
};

const crossContainerTree: ElementTreeResponse = {
  '42': [
    makeSection(2, [
      makeRow(11, [makeColumn(50, [], 11)], 2),
      makeRow(12, [makeColumn(51, [], 12)], 2),
    ], 42),
    makeSection(3, [
      makeRow(13, [makeColumn(52, [], 13)], 3),
      makeRow(14, [makeColumn(53, [], 14)], 3),
    ], 42),
  ],
};

// --- Tests ---

describe('useDragAndDrop', () => {
  const defaultOptions = {
    tree: testTree,
    onReorder: vi.fn(),
  };

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('dndContextProps', () => {
    it('returns sensors array', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      expect(result.current.dndContextProps.sensors).toBeDefined();
      expect(result.current.dndContextProps.sensors.length).toBeGreaterThan(0);
    });

    it('returns a collision detection function', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      expect(typeof result.current.dndContextProps.collisionDetection).toBe('function');
    });

    it('returns all required event handlers', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      const { dndContextProps } = result.current;
      expect(typeof dndContextProps.onDragStart).toBe('function');
      expect(typeof dndContextProps.onDragOver).toBe('function');
      expect(typeof dndContextProps.onDragEnd).toBe('function');
      expect(typeof dndContextProps.onDragCancel).toBe('function');
    });
  });

  it('initializes with null dragState', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.dragState).toBeNull();
  });

  it('initializes with null pendingTree', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.pendingTree).toBeNull();
  });

  describe('handleDragStart', () => {
    it('sets dragState for a valid element', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });

      const state: DragState = result.current.dragState!;
      expect(state).not.toBeNull();
      expect(state.activeId).toBe('element-30');
      expect(state.activeType).toBe('element');
      expect(state.activeNode.id).toBe(30);
    });

    it('sets dragState for a container node', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('column-20'));
      });

      const state = result.current.dragState!;
      expect(state.activeType).toBe('column');
      expect(state.activeNode.id).toBe(20);
    });

    it('does not set dragState for an invalid composite ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('invalid'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not set dragState for a non-existent node ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-999'));
      });

      expect(result.current.dragState).toBeNull();
    });
  });

  describe('handleDragEnd', () => {
    it('clears dragState', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not call onReorder when over is null', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', null));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder when active === over (no-op)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-30'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('calls onReorder for a same-container sibling swap', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        30, 20, 31, expect.any(Function),
      );
    });

    it('does not call onReorder for invalid active ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('invalid', 'element-31'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder for invalid over ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'invalid'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });
  });

  describe('handleDragOver + handleDragEnd (cross-container integration)', () => {
    it('sets pendingTree on cross-container dragOver', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });

      expect(result.current.pendingTree).not.toBeNull();
    });

    it('calls onReorder after cross-container dragOver + dragEnd', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', 'row-14'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      // Verify the element moved to section 3
      expect(onReorder).toHaveBeenCalledWith(
        11, 3, expect.any(Number), expect.any(Function),
      );
    });

    it('does not set pendingTree for same-container dragOver', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-12'));
      });

      expect(result.current.pendingTree).toBeNull();
    });
  });

  describe('deferred pendingTree clearing', () => {
    it('pendingTree is NOT cleared until clearPendingTree callback is invoked', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', 'row-14'));
      });

      expect(result.current.pendingTree).not.toBeNull();
      expect(onReorder).toHaveBeenCalledTimes(1);

      const clearFn = onReorder.mock.calls[0][3] as () => void;
      act(() => {
        clearFn();
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('pendingTree IS cleared immediately on early return (no over)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', null));
      });

      expect(result.current.pendingTree).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('pendingTree IS cleared immediately on early return (invalid IDs)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('invalid', 'row-14'));
      });

      expect(result.current.pendingTree).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });
  });

  describe('handleDragCancel', () => {
    it('clears dragState without calling onReorder', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent('element-30'));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('clears pendingTree on cancel', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent('row-11'));
      });

      expect(result.current.pendingTree).toBeNull();
    });
  });
});
