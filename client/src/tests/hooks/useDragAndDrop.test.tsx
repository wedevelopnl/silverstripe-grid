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
import { isContainerNode } from '@/types/elements';
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

// Tree for cross-container drag-over with non-empty target containers.
// Section 5 has 2 rows with columns containing elements — provides a
// non-empty target when dragging a row from section 4 into section 5.
const crossContainerNonEmptyTree: ElementTreeResponse = {
  '42': [
    makeSection(4, [
      makeRow(60, [makeColumn(70, [makeElement(80, 70)], 60)], 4),
    ], 42),
    makeSection(5, [
      makeRow(61, [makeColumn(71, [makeElement(81, 71)], 61)], 5),
      makeRow(62, [makeColumn(72, [makeElement(82, 72)], 62)], 5),
    ], 42),
  ],
};

// --- Event factories with pointer geometry ---

function makeActiveWithRect(
  id: string,
  initial: { left: number; top: number },
  translated: { left: number; top: number },
): Active {
  const fullInitial = { ...initial, width: 0, height: 0, right: initial.left, bottom: initial.top };
  const fullTranslated = { ...translated, width: 0, height: 0, right: translated.left, bottom: translated.top };
  return {
    id,
    data: createMutableRef(undefined),
    rect: createMutableRef({ initial: fullInitial, translated: fullTranslated }),
  };
}

function makeOverWithRect(
  id: string,
  rect: { width: number; height: number; top: number; left: number; right: number; bottom: number },
): Over {
  return {
    id,
    data: createMutableRef(undefined),
    rect,
    disabled: false,
  };
}

function makeDragOverEventWithPointer(
  activeId: string,
  overId: string,
  clientX: number,
  clientY: number,
  overRect: { width: number; height: number; top: number; left: number; right: number; bottom: number },
): DragOverEvent {
  return {
    active: makeActiveWithRect(activeId, { left: 0, top: 0 }, { left: 0, top: 0 }),
    over: makeOverWithRect(overId, overRect),
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX, clientY }),
  };
}

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

  describe('sensor configuration', () => {
    it('sensors have activation constraint with distance threshold', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      const sensors = result.current.dndContextProps.sensors;

      // The sensor descriptor should carry options with an activationConstraint
      expect(sensors).toHaveLength(1);
      const sensorDescriptor = sensors[0];
      expect(sensorDescriptor.options).toBeDefined();
      expect(sensorDescriptor.options).toHaveProperty('activationConstraint');
      expect((sensorDescriptor.options as { activationConstraint: { distance: number } }).activationConstraint.distance).toBe(8);
    });
  });

  describe('handleDragOver early return', () => {
    it('does not set pendingTree when over is null', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      // Create a DragOverEvent with null over
      const event: DragOverEvent = {
        active: makeActive('row-11'),
        over: null,
        collisions: [],
        delta: { x: 0, y: 0 },
        activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
      };

      act(() => {
        result.current.dndContextProps.onDragOver(event);
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('does not set pendingTree when active === over', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-11'));
      });

      expect(result.current.pendingTree).toBeNull();
    });
  });

  describe('cross-container drag-over with non-empty target', () => {
    it('places element after last child when dragging into container with children', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerNonEmptyTree, onReorder: vi.fn() }),
      );

      // Drag row-60 (in section-4) over section-5 (cross-type: row → section).
      // Section 5 has children [row-61, row-62], so afterElementId should be 62.
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-60', 'section-5'));
      });

      expect(result.current.pendingTree).not.toBeNull();

      // Verify row-60 was placed at the end (after row-62) in section-5
      const sections = result.current.pendingTree!['42'];
      const section5 = sections.find((s) => s.id === 5);
      expect(section5).toBeDefined();
      expect(isContainerNode(section5!)).toBe(true);
      if (isContainerNode(section5!)) {
        const childIds = section5!.children!.map((c) => c.id);
        expect(childIds).toEqual([61, 62, 60]);
      }
    });

    it('calls onReorder with correct afterElementId after cross-type drag + drop', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerNonEmptyTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-60', 'section-5'));
      });

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-60', 'section-5'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      // row-60 moved to section-5, placed after row-62 (last child)
      expect(onReorder).toHaveBeenCalledWith(
        60, 5, 62, expect.any(Function),
      );
    });
  });

  describe('direction detection with pointer geometry', () => {
    // Over rect for rows: Y-axis direction. Center Y = 100 + 100/2 = 150.
    const rowRect = { top: 100, left: 0, width: 200, height: 100, right: 200, bottom: 200 };

    it('places before target when pointer is above center (cross-parent)', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      // Drag row-11 (section-2) over row-14 (section-3, second row).
      // Pointer Y=120 < center Y=150 → "before" → afterElementId = siblings[0].id = 13
      // Expected: row-11 placed after row-13, before row-14
      act(() => {
        result.current.dndContextProps.onDragOver(
          makeDragOverEventWithPointer('row-11', 'row-14', 50, 120, rowRect),
        );
      });

      expect(result.current.pendingTree).not.toBeNull();
      const sections = result.current.pendingTree!['42'];
      const section3 = sections.find((s) => s.id === 3);
      expect(isContainerNode(section3!)).toBe(true);
      if (isContainerNode(section3!)) {
        const childIds = section3!.children!.map((c) => c.id);
        expect(childIds).toEqual([13, 11, 14]);
      }
    });

    it('places after target when pointer is below center (cross-parent)', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      // Drag row-11 (section-2) over row-14 (section-3, second row).
      // Pointer Y=180 >= center Y=150 → "after" → afterElementId = overParsed.id = 14
      // Expected: row-11 placed after row-14
      act(() => {
        result.current.dndContextProps.onDragOver(
          makeDragOverEventWithPointer('row-11', 'row-14', 50, 180, rowRect),
        );
      });

      expect(result.current.pendingTree).not.toBeNull();
      const sections = result.current.pendingTree!['42'];
      const section3 = sections.find((s) => s.id === 3);
      expect(isContainerNode(section3!)).toBe(true);
      if (isContainerNode(section3!)) {
        const childIds = section3!.children!.map((c) => c.id);
        expect(childIds).toEqual([13, 14, 11]);
      }
    });

    it('places at start when pointer is before first sibling (cross-parent)', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      // Drag row-11 (section-2) over row-13 (section-3, FIRST row).
      // Pointer Y=120 < center Y=150 → "before" → overIdx=0, overIdx > 0 is false
      // → afterElementId = null → placed at beginning
      act(() => {
        result.current.dndContextProps.onDragOver(
          makeDragOverEventWithPointer('row-11', 'row-13', 50, 120, rowRect),
        );
      });

      expect(result.current.pendingTree).not.toBeNull();
      const sections = result.current.pendingTree!['42'];
      const section3 = sections.find((s) => s.id === 3);
      expect(isContainerNode(section3!)).toBe(true);
      if (isContainerNode(section3!)) {
        const childIds = section3!.children!.map((c) => c.id);
        expect(childIds).toEqual([11, 13, 14]);
      }
    });
  });
});
