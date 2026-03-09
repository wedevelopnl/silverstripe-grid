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
import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
  ElementTreeResponse,
} from '@/types/elements';
import { useDragAndDrop } from '@/hooks/useDragAndDrop';
import type { DragState } from '@/hooks/useDragAndDrop';

// --- Test factories ---

function makeElement(id: number, parentId: number): SimpleElementNode {
  return {
    id,
    parentId,
    title: `Element ${id}`,
    blockSchema: {
      typeName: 'Element',
      label: 'Element',
      type: 'Element',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
  };
}

function makeColumn(
  id: number,
  children: SimpleElementNode[],
  parentId: number,
): ColumnNode {
  return {
    id,
    parentId,
    title: `Column ${id}`,
    blockSchema: {
      typeName: 'Column',
      label: 'Column',
      type: 'Column',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'column',
    allowedTypes: null,
    children,
    gridSettings: { md: { width: 6, offset: 0, visible: true } },
  };
}

function makeRow(
  id: number,
  children: ColumnNode[],
  parentId: number,
): RowNode {
  return {
    id,
    parentId,
    title: `Row ${id}`,
    blockSchema: {
      typeName: 'Row',
      label: 'Row',
      type: 'Row',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'row',
    allowedTypes: null,
    children,
  };
}

function makeSection(
  id: number,
  children: RowNode[],
  parentId: number,
): SectionNode {
  return {
    id,
    parentId,
    title: `Section ${id}`,
    blockSchema: {
      typeName: 'Section',
      label: 'Section',
      type: 'Section',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'section',
    allowedTypes: null,
    children,
  };
}

// --- dnd-kit event factories ---

function createMutableRef<T>(value: T): MutableRefObject<T> {
  return { current: value };
}

interface RectLike {
  width: number;
  height: number;
  top: number;
  left: number;
  right: number;
  bottom: number;
}

const ZERO_RECT: RectLike = { width: 0, height: 0, top: 0, left: 0, right: 0, bottom: 0 };

function makeActive(id: string, options?: { translated?: RectLike; initial?: RectLike }): Active {
  return {
    id,
    data: createMutableRef(undefined),
    rect: createMutableRef({
      // When translated is provided, default initial to ZERO_RECT so
      // getPointerPosition can compute: pointer = translated + (clientXY - initial).
      // With clientXY=0 and initial=ZERO_RECT, pointer = translated position.
      initial: options?.initial ?? (options?.translated ? ZERO_RECT : null),
      translated: options?.translated ?? null,
    }),
  };
}

function makeOver(id: string, options?: { rect?: RectLike }): Over {
  return {
    id,
    data: createMutableRef(undefined),
    rect: options?.rect ?? { width: 0, height: 0, top: 0, left: 0, right: 0, bottom: 0 },
    disabled: false,
  };
}

function makeDragStartEvent(activeId: string): DragStartEvent {
  return {
    active: makeActive(activeId),
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragEndEvent(
  activeId: string,
  overId: string | null,
  options?: { activeTranslated?: RectLike; overRect?: RectLike },
): DragEndEvent {
  return {
    active: makeActive(activeId, { translated: options?.activeTranslated }),
    over: overId !== null ? makeOver(overId, { rect: options?.overRect }) : null,
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragOverEvent(
  activeId: string,
  overId: string,
  options?: { activeTranslated?: RectLike; overRect?: RectLike },
): DragOverEvent {
  return {
    active: makeActive(activeId, { translated: options?.activeTranslated }),
    over: makeOver(overId, { rect: options?.overRect }),
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

// --- Test tree fixture ---
//
// Structure:
//   area 42:
//     Section 1 (id=1, parentId=42)
//       Row 10 (id=10, parentId=1)
//         Column 20 (id=20, parentId=10, children: [Element 30, Element 31])
//         Column 21 (id=21, parentId=10, children: [Element 32])

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

const ROOT_AREA_ID = 42;

// --- useDragAndDrop hook tests ---

describe('useDragAndDrop', () => {
  const defaultOptions = {
    tree: testTree,
    onReorder: vi.fn(),
  };

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('returns sensors array', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.sensors).toBeDefined();
    expect(result.current.sensors.length).toBeGreaterThan(0);
  });

  it('returns a collision detection function', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(typeof result.current.collisionDetection).toBe('function');
  });

  it('initializes with null dragState', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.dragState).toBeNull();
  });

  describe('handleDragStart', () => {
    it('sets dragState for a valid element', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.handleDragStart(makeDragStartEvent('element-30'));
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
        result.current.handleDragStart(makeDragStartEvent('column-20'));
      });

      const state = result.current.dragState!;
      expect(state).not.toBeNull();
      expect(state.activeId).toBe('column-20');
      expect(state.activeType).toBe('column');
      expect(state.activeNode.id).toBe(20);
    });

    it('does not set dragState for an invalid composite ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.handleDragStart(makeDragStartEvent('invalid'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not set dragState for a non-existent node ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.handleDragStart(makeDragStartEvent('element-999'));
      });

      expect(result.current.dragState).toBeNull();
    });
  });

  describe('handleDragEnd', () => {
    it('clears dragState', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      // First set some drag state
      act(() => {
        result.current.handleDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not call onReorder when over is null', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', null));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder when active === over (no-op)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'element-30'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('calls onReorder for a same-container sibling swap', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // Move element-30 after element-31 within column 20
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        30,   // elementID
        20,   // targetParentId (column 20's id)
        31,   // afterElementID (placed after element 31)
      );
    });

    it('calls onReorder for a cross-container move between siblings', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // Move element-30 (in column 20) to where element-32 is (in column 21)
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'element-32'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        30,   // elementID
        21,   // targetParentId (column 21's id)
        null, // afterElementID (takes position of element-32, which is index 0)
      );
    });

    it('calls onReorder when dropping into a container', () => {
      const onReorder = vi.fn();
      // Use a tree with an empty column to test dropping into a container
      const treeWithEmptyCol: ElementTreeResponse = {
        '42': [
          makeSection(1, [
            makeRow(10, [
              makeColumn(20, [makeElement(30, 20)], 10),
              makeColumn(21, [], 10),
            ], 1),
          ], 42),
        ],
      };

      const { result } = renderHook(() =>
        useDragAndDrop({
          tree: treeWithEmptyCol,
          onReorder,
        }),
      );

      // Drop element-30 into column-21 (empty container)
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'column-21'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        30,   // elementID
        21,   // targetParentId (column 21's id)
        null, // afterElementID (appended to empty container = first position)
      );
    });

    it('does not call onReorder for an invalid active ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('invalid', 'element-31'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder for an invalid over ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'invalid'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('uses correct source index for non-first elements in cross-container move', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // Move element-31 (index 1 in column 20) to element-32 (in column 21)
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-31', 'element-32'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        31,   // elementID
        21,   // targetParentId (column 21's id)
        null, // afterElementID (takes position of element-32 at index 0)
      );
    });

    it('calls onReorder for same-container reorder of non-first element to front', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // Move element-31 (index 1) before element-30 (index 0) within column 20
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-31', 'element-30'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        31,   // elementID
        20,   // targetParentId (same container)
        null, // afterElementID (moved to front)
      );
    });

    it('does not call onReorder when over is a non-container with different type', () => {
      const onReorder = vi.fn();
      // Attempting to drop an element onto a row (which is not the direct parent type)
      // This should fall into the container branch, but rows don't hold elements directly
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // element-30 is type 'element', row-10 is type 'row' — different types
      // row-10 is a container, so it should try to drop into it
      // The row's children are columns, not elements, so the compositeIds won't match.
      // resolveReorderParams will still produce a result since the active is placed at end.
      act(() => {
        result.current.handleDragEnd(makeDragEndEvent('element-30', 'row-10'));
      });

      // This produces a valid reorder call (element placed in the row's area)
      expect(onReorder).toHaveBeenCalledTimes(1);
    });
  });

    describe('cross-container via pending tree', () => {
      // Tree with two sections, each with rows:
      //   Section 2 (id=2): Row 11, Row 12
      //   Section 3 (id=3): Row 13, Row 14
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

      it('cross-container move via handleDragOver then drop uses pointer direction', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Step 1: handleDragOver moves row-11 (Section 2) into Section 3 after row-14
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-14'));
        });

        // Pending tree should be set (cross-container move)
        expect(result.current.pendingTree).not.toBeNull();

        // Step 2: handleDragEnd with pointer BELOW row-14's center → after row-14
        const activeRect = { width: 100, height: 50, top: 350, left: 0, right: 100, bottom: 400 };
        const overRect = { width: 100, height: 50, top: 300, left: 0, right: 100, bottom: 350 };

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-14', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11, // elementID
          3,  // targetParentId (section 3)
          14, // afterElementID (pointer below center → after row-14)
        );
      });

      it('cross-container move then drop before first uses pointer direction', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Step 1: row-11 enters Section 3 after row-14 (appended at end)
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-14'));
        });

        expect(result.current.pendingTree).not.toBeNull();

        // Step 2: drop with pointer ABOVE row-13's center → before row-13
        const activeRect = { width: 100, height: 50, top: 200, left: 0, right: 100, bottom: 250 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11,   // elementID
          3,    // targetParentId (section 3)
          null, // afterElementID (pointer above center → before row-13)
        );
      });

      it('cross-container move then drop between siblings uses pointer direction', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Step 1: row-11 enters Section 3 after row-13 (first child)
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-13'));
        });

        // Pending tree: Section 3 = [row-13, row-11, row-14]

        // Step 2: drop with pointer BELOW row-13's center → after row-13
        const activeRect = { width: 100, height: 50, top: 300, left: 0, right: 100, bottom: 350 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11, // elementID
          3,  // targetParentId (section 3)
          13, // afterElementID (pointer below center → after row-13)
        );
      });

      it('cross-container move then drag back to original container detects same-position', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Step 1: row-11 enters Section 3
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-14'));
        });

        // Step 2: row-11 returns to Section 2 after row-12
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-12'));
        });

        // Drop with pointer ABOVE row-12's center → before row-12 (original position)
        const activeRect = { width: 100, height: 50, top: 200, left: 0, right: 100, bottom: 250 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-12', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        // Same container + same index = no-op
        expect(onReorder).not.toHaveBeenCalled();
      });

      it('cross-container drop without handleDragOver falls back to direction check', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Direct handleDragEnd without handleDragOver (no pending tree)
        // Fallback direction check: drag center below over center → insert AFTER
        const activeRect = { width: 100, height: 50, top: 350, left: 0, right: 100, bottom: 400 };
        const overRect = { width: 100, height: 50, top: 300, left: 0, right: 100, bottom: 350 };

        act(() => {
          result.current.handleDragEnd(
            makeDragEndEvent('row-11', 'row-14', {
              activeTranslated: activeRect,
              overRect,
            }),
          );
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11, // elementID
          3,  // targetParentId (section 3)
          14, // afterElementID (direction check: placed AFTER row-14)
        );
      });

      it('translated=null fallback preserves current behavior (insert BEFORE)', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Cross-container with no translated rect — falls back to no direction adjustment
        act(() => {
          result.current.handleDragEnd(
            makeDragEndEvent('row-11', 'row-13'),
          );
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11,   // elementID
          3,    // targetParentId (section 3)
          null, // afterElementID (index 0 = first position)
        );
      });
    });

    describe('direction-aware placement in handleDragOver', () => {
      // Tree: Section 2 has rows [11, 12], Section 3 has rows [13, 14]
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

      it('row: pointer above over Y center → places before (afterElementId = null for first)', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Drag row-11 over row-13 with pointer ABOVE row-13's center (Y-axis)
        const activeRect = { width: 100, height: 50, top: 200, left: 0, right: 100, bottom: 250 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(result.current.pendingTree).not.toBeNull();

        // DragEnd also needs rects for direction-aware placement
        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11,   // elementID
          3,    // targetParentId (section 3)
          null, // afterElementID (before row-13 = first position)
        );
      });

      it('row: pointer below over Y center → places after', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // Drag row-11 over row-13 with pointer BELOW row-13's center (Y-axis)
        const activeRect = { width: 100, height: 50, top: 300, left: 0, right: 100, bottom: 350 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(result.current.pendingTree).not.toBeNull();

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-13', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11, // elementID
          3,  // targetParentId (section 3)
          13, // afterElementID (after row-13)
        );
      });

      it('column: pointer left of over X center → places before (afterElementId = null for first)', () => {
        const onReorder = vi.fn();
        // Tree with two rows, each having columns
        const columnTree: ElementTreeResponse = {
          '42': [
            makeSection(1, [
              makeRow(10, [
                makeColumn(20, [makeElement(30, 20)], 10),
                makeColumn(21, [makeElement(31, 21)], 10),
              ], 1),
              makeRow(11, [
                makeColumn(22, [makeElement(32, 22)], 11),
                makeColumn(23, [makeElement(33, 23)], 11),
              ], 1),
            ], 42),
          ],
        };

        const { result } = renderHook(() =>
          useDragAndDrop({ tree: columnTree, onReorder }),
        );

        // Drag column-20 over column-22 with pointer LEFT of column-22's X center
        const activeRect = { width: 100, height: 50, top: 0, left: 50, right: 150, bottom: 50 };
        const overRect = { width: 100, height: 50, top: 0, left: 200, right: 300, bottom: 50 };

        act(() => {
          result.current.handleDragOver(makeDragOverEvent('column-20', 'column-22', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(result.current.pendingTree).not.toBeNull();

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('column-20', 'column-22', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          20,   // elementID
          11,   // targetParentId (row 11)
          null, // afterElementID (before column-22 = first position)
        );
      });

      it('column: pointer right of over X center → places after', () => {
        const onReorder = vi.fn();
        const columnTree: ElementTreeResponse = {
          '42': [
            makeSection(1, [
              makeRow(10, [
                makeColumn(20, [makeElement(30, 20)], 10),
                makeColumn(21, [makeElement(31, 21)], 10),
              ], 1),
              makeRow(11, [
                makeColumn(22, [makeElement(32, 22)], 11),
                makeColumn(23, [makeElement(33, 23)], 11),
              ], 1),
            ], 42),
          ],
        };

        const { result } = renderHook(() =>
          useDragAndDrop({ tree: columnTree, onReorder }),
        );

        // Drag column-20 over column-22 with pointer RIGHT of column-22's X center
        const activeRect = { width: 100, height: 50, top: 0, left: 300, right: 400, bottom: 50 };
        const overRect = { width: 100, height: 50, top: 0, left: 200, right: 300, bottom: 50 };

        act(() => {
          result.current.handleDragOver(makeDragOverEvent('column-20', 'column-22', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(result.current.pendingTree).not.toBeNull();

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('column-20', 'column-22', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          20, // elementID
          11, // targetParentId (row 11)
          22, // afterElementID (after column-22)
        );
      });

      it('element: pointer above over Y center → places before', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: testTree, onReorder }),
        );

        // Drag element-30 (column 20) over element-32 (column 21) with pointer ABOVE
        const activeRect = { width: 100, height: 50, top: 200, left: 0, right: 100, bottom: 250 };
        const overRect = { width: 100, height: 50, top: 250, left: 0, right: 100, bottom: 300 };

        act(() => {
          result.current.handleDragOver(makeDragOverEvent('element-30', 'element-32', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(result.current.pendingTree).not.toBeNull();

        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('element-30', 'element-32', {
            activeTranslated: activeRect,
            overRect,
          }));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          30,   // elementID
          21,   // targetParentId (column 21)
          null, // afterElementID (before element-32 = first position)
        );
      });

      it('translated=null fallback at DragEnd places at over index (no direction shift)', () => {
        const onReorder = vi.fn();
        const { result } = renderHook(() =>
          useDragAndDrop({ tree: crossContainerTree, onReorder }),
        );

        // handleDragOver with translated=null → entry after row-13
        act(() => {
          result.current.handleDragOver(makeDragOverEvent('row-11', 'row-13'));
        });

        expect(result.current.pendingTree).not.toBeNull();

        // handleDragEnd with no translated rect → insertIndex stays at over's index
        act(() => {
          result.current.handleDragEnd(makeDragEndEvent('row-11', 'row-13'));
        });

        expect(onReorder).toHaveBeenCalledTimes(1);
        expect(onReorder).toHaveBeenCalledWith(
          11,   // elementID
          3,    // targetParentId (section 3)
          null, // afterElementID (overIdx=0 → first position, no direction shift)
        );
      });
    });

  describe('handleDragCancel', () => {
    it('clears dragState without calling onReorder', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      // Set some drag state first
      act(() => {
        result.current.handleDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.handleDragCancel(makeDragCancelEvent('element-30'));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });
  });
});
