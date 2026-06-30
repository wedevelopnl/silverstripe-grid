import type {
  ClientRect,
  DragCancelEvent,
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
} from '@dnd-kit/core'
import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createActive, createOver } from '@/testing/dndHelpers'
import { createDroppable, createDroppableWithRect, makeDomRect } from '@/testing/dndRectFactories'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import { buildDraggableId } from '@/types/dnd'
import type { TreeApiResponse } from '@/types/elements'
import type { UseDragAndDropOptions } from './useDragAndDrop'
import { useDragAndDrop, useDragContext } from './useDragAndDrop'

beforeEach(() => {
  resetIdCounter()
})

// --- Synthetic event builders ---

function makeDragStartEvent(activeId: string): DragStartEvent {
  return {
    active: createActive(activeId),
    activatorEvent: new Event('pointer'),
  } as unknown as DragStartEvent
}

function makeDragOverEvent(activeId: string, overId: string | null): DragOverEvent {
  return {
    active: createActive(activeId),
    over: overId !== null ? createOver(overId) : null,
    activatorEvent: new Event('pointer'),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragOverEvent
}

function makeDragEndEvent(activeId: string, overId: string | null): DragEndEvent {
  return {
    active: createActive(activeId),
    over: overId !== null ? createOver(overId) : null,
    activatorEvent: new Event('pointer'),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragEndEvent
}

function makeDragCancelEvent(): DragCancelEvent {
  return {
    active: createActive('element-1'),
    activatorEvent: new Event('pointer'),
  } as unknown as DragCancelEvent
}

/**
 * Drag-end event whose `activatorEvent` is a real PointerEvent. This is the
 * only way to exercise `getPointerPosition`'s pointer-available branch — it
 * bails to null unless `activatorEvent instanceof PointerEvent`. The over
 * element's rect (top/height) is set explicitly so the test controls the
 * midpoint the resolved pointer is compared against.
 */
function makePointerDragEndEvent(
  activeId: string,
  overId: string,
  clientX: number,
  clientY: number,
  overRect: { top: number; left: number; width: number; height: number },
  activeRects?: { initial: RectLike; translated: RectLike },
): DragEndEvent {
  // By default the active rect's initial === translated (default helper rects),
  // so getPointerPosition resolves pointer.{x,y} === client{X,Y}. Pass distinct
  // `activeRects` to exercise the grab-point offset math in getPointerPosition
  // at DROP time (the only place onReorder's `after` is computed).
  const over = createOver(overId, overRect)
  if (activeRects) {
    return {
      active: {
        id: activeId,
        rect: { current: { initial: activeRects.initial, translated: activeRects.translated } },
        data: { current: undefined },
      },
      over,
      activatorEvent: new PointerEvent('pointerdown', { clientX, clientY }),
      collisions: [],
      delta: { x: 0, y: 0 },
    } as unknown as DragEndEvent
  }
  return {
    active: createActive(activeId),
    over,
    activatorEvent: new PointerEvent('pointerdown', { clientX, clientY }),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragEndEvent
}

interface RectLike {
  top: number
  left: number
  width: number
  height: number
}

/**
 * Drag-over event whose `activatorEvent` is a real PointerEvent, so
 * `getPointerPosition` resolves a non-null pointer and the same-type branch's
 * direction logic (lines 196-206) runs. `initialRect`/`translatedRect` default
 * to the same zero-origin rect (pointer === client coords); pass distinct
 * values to exercise the grab-point offset math in `getPointerPosition`.
 */
function makePointerDragOverEvent(
  activeId: string,
  overId: string,
  clientX: number,
  clientY: number,
  overRect: RectLike,
  activeRects?: { initial: RectLike; translated: RectLike },
): DragOverEvent {
  const baseRect: RectLike = { top: 0, left: 0, width: 200, height: 50 }
  // biome-ignore lint/suspicious/noUnnecessaryConditions: activeRects is an optional param (`… | undefined`); the ?? baseRect fallback is required — dropping it fails typecheck.
  const initial = activeRects?.initial ?? baseRect
  // biome-ignore lint/suspicious/noUnnecessaryConditions: activeRects is an optional param (`… | undefined`); the ?? baseRect fallback is required — dropping it fails typecheck.
  const translated = activeRects?.translated ?? baseRect
  return {
    active: {
      id: activeId,
      rect: { current: { initial, translated } },
      data: { current: undefined },
    },
    over: createOver(overId, overRect),
    activatorEvent: new PointerEvent('pointerdown', { clientX, clientY }),
    collisions: [],
    delta: { x: 0, y: 0 },
  } as unknown as DragOverEvent
}

// --- Tree builders ---

function buildSingleColumnTree(pageId = 1) {
  const element1 = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
  const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 30 } })
  const column = createColumnNode({
    id: 30,
    parent: { type: 'row', id: 20 },
    children: [element1, element2],
  })
  const row = createRowNode({
    id: 20,
    parent: { type: 'section', id: 10 },
    children: [column],
  })
  const section = createSectionNode({
    id: 10,
    parent: { type: 'page', id: pageId },
    children: [row],
  })
  const tree = createTreeApiResponse({ pageId, sections: [section] })
  return { tree, element1, element2, column, row, section }
}

function buildTwoColumnTree(pageId = 1) {
  const element1 = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
  const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })
  const col1 = createColumnNode({
    id: 30,
    parent: { type: 'row', id: 20 },
    children: [element1],
  })
  const col2 = createColumnNode({
    id: 31,
    parent: { type: 'row', id: 20 },
    children: [element2],
  })
  const row = createRowNode({
    id: 20,
    parent: { type: 'section', id: 10 },
    children: [col1, col2],
  })
  const section = createSectionNode({
    id: 10,
    parent: { type: 'page', id: pageId },
    children: [row],
  })
  const tree = createTreeApiResponse({ pageId, sections: [section] })
  return { tree, element1, element2, col1, col2, row, section }
}

function renderDndHook(options: Partial<UseDragAndDropOptions> = {}) {
  const defaultTree = buildSingleColumnTree().tree
  const onReorder = vi.fn()
  const mergedOptions: UseDragAndDropOptions = {
    tree: options.tree ?? defaultTree,
    onReorder: options.onReorder ?? onReorder,
  }
  const hookResult = renderHook(() => useDragAndDrop(mergedOptions))
  return { ...hookResult, onReorder }
}

describe('useDragAndDrop', () => {
  describe('initial state', () => {
    it('returns null dragState and null pendingTree', () => {
      const { result } = renderDndHook()
      expect(result.current.dragState).toBeNull()
      expect(result.current.pendingTree).toBeNull()
    })

    it('exposes dndContextProps with handler functions', () => {
      const { result } = renderDndHook()
      expect(typeof result.current.dndContextProps.onDragStart).toBe('function')
      expect(typeof result.current.dndContextProps.onDragOver).toBe('function')
      expect(typeof result.current.dndContextProps.onDragEnd).toBe('function')
      expect(typeof result.current.dndContextProps.onDragCancel).toBe('function')
    })
  })

  describe('useDragContext default', () => {
    it('defaults pendingActive to false with no provider in the tree', () => {
      // DragContext's default value is what block components read when they sit
      // outside an active provider (e.g. before the first drag). pendingActive
      // must default to false so SortableContext uses its normal sorting
      // strategy — a `true` default would switch every block to the no-op
      // strategy permanently, breaking same-container reorder previews.
      const { result } = renderHook(() => useDragContext())
      expect(result.current.pendingActive).toBe(false)
      expect(result.current.activeType).toBeNull()
    })
  })

  describe('onDragStart', () => {
    it('sets dragState with active element info', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      expect(result.current.dragState).not.toBeNull()
      expect(result.current.dragState?.activeId).toBe(activeId)
      expect(result.current.dragState?.activeType).toBe('element')
      expect(result.current.dragState?.activeNode.self.id).toBe(element1.self.id)
    })

    it('ignores invalid draggable IDs', () => {
      const { tree } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('invalid-id'))
      })

      expect(result.current.dragState).toBeNull()
    })

    it('ignores IDs for elements not in the tree', () => {
      const { tree } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      act(() => {
        result.current.dndContextProps.onDragStart(
          makeDragStartEvent(buildDraggableId('element', 999)),
        )
      })

      expect(result.current.dragState).toBeNull()
    })

    it('works for container draggable types', () => {
      const { tree, column } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('column', column.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      expect(result.current.dragState).not.toBeNull()
      expect(result.current.dragState?.activeType).toBe('column')
      expect(result.current.dragState?.activeNode.self.id).toBe(column.self.id)
    })
  })

  describe('onDragCancel', () => {
    it('clears dragState after a drag start', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      expect(result.current.dragState).not.toBeNull()

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent())
      })

      expect(result.current.dragState).toBeNull()
    })

    it('clears pendingTree after a cross-container drag over', () => {
      const { tree, element1, col2 } = buildTwoColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('column', col2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId))
      })
      expect(result.current.pendingTree).not.toBeNull()

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent())
      })

      expect(result.current.pendingTree).toBeNull()
      expect(result.current.dragState).toBeNull()
    })
  })

  describe('onDragOver', () => {
    it('applies pending tree for cross-container move (element over different-type container)', () => {
      const { tree, element1, col2 } = buildTwoColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('column', col2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId))
      })

      expect(result.current.pendingTree).not.toBeNull()
    })

    it('does not apply pending tree for same-container move', () => {
      const { tree, element1, element2 } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId))
      })

      expect(result.current.pendingTree).toBeNull()
    })

    it('ignores over events when over is null', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, null))
      })

      expect(result.current.pendingTree).toBeNull()
    })

    it('ignores over events when active.id equals over.id', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, activeId))
      })

      expect(result.current.pendingTree).toBeNull()
    })

    it('applies pending tree when dragging element over sibling in different container', () => {
      const { tree, element1, element2 } = buildTwoColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId))
      })

      expect(result.current.pendingTree).not.toBeNull()
    })
  })

  describe('onDragOver same-type cross-container direction', () => {
    // These exercise the same-type branch (element over element in a DIFFERENT
    // container) WITH a real pointer, which the synthetic-Event tests never
    // reach: getPointerPosition bails to null on a plain Event, short-circuiting
    // the `pointer !== null && resolveInsertDirection(...) === 'before'` guard.
    // The resulting pending-tree order is the observable contract.

    // col30 holds the dragged element; col31 is the cross-container target whose
    // membership varies per test. Both columns share row20.
    function buildCrossContainerElementTree(
      col31Children: ReturnType<typeof createSimpleElement>[],
    ) {
      const moved = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
      const col30 = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [moved],
      })
      const col31 = createColumnNode({
        id: 31,
        parent: { type: 'row', id: 20 },
        children: col31Children,
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [col30, col31],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      return { tree: createTreeApiResponse({ pageId: 1, sections: [section] }), moved }
    }

    function targetColumnChildIds(pendingTree: TreeApiResponse | null): number[] {
      // section → row → col31 (second column) → its children ids
      const section = pendingTree?.nodes[0] as ReturnType<typeof createSectionNode>
      const row = section.children?.[0] as ReturnType<typeof createRowNode>
      const col31 = row.children?.[1] as ReturnType<typeof createColumnNode>
      return col31.children?.map((c) => c.self.id) ?? []
    }

    // Over-element (id 41) rect: top=0 height=50 → midpoint Y=25. clientY=5 is
    // ABOVE the midpoint ('before'); clientY=45 is BELOW it ('after').
    const OVER_RECT = { top: 0, left: 0, width: 200, height: 50 }

    it('places before an over-element that has a prior sibling (after = that sibling)', () => {
      // col31 = [x(50), element2(41)]. Drag element1(40) before element2 →
      // overIdx=1 (>0) so after = siblings[0] = x(50) → [x, element1, element2].
      const x = createSimpleElement({ id: 50, parent: { type: 'column', id: 31 } })
      const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })
      const { tree } = buildCrossContainerElementTree([x, element2])
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', 40)
      const overId = buildDraggableId('element', 41)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(
          makePointerDragOverEvent(activeId, overId, 100, 5, OVER_RECT),
        )
      })

      expect(targetColumnChildIds(result.current.pendingTree)).toEqual([50, 40, 41])
    })

    it('places before an over-element at the container head (after = null)', () => {
      // col31 = [element2(41)] only. Drag element1(40) before element2 →
      // overIdx=0 (NOT >0) so after = null → element1 at head → [element1, element2].
      const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })
      const { tree } = buildCrossContainerElementTree([element2])
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', 40)
      const overId = buildDraggableId('element', 41)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(
          makePointerDragOverEvent(activeId, overId, 100, 5, OVER_RECT),
        )
      })

      expect(targetColumnChildIds(result.current.pendingTree)).toEqual([40, 41])
    })

    it('places after an over-element when the pointer is below its midpoint (after = over-element)', () => {
      // col31 = [element2(41)]. Drag element1(40) AFTER element2 (clientY below
      // midpoint) → after = overNode.self = element2 → [element2, element1].
      const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })
      const { tree } = buildCrossContainerElementTree([element2])
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', 40)
      const overId = buildDraggableId('element', 41)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(
          makePointerDragOverEvent(activeId, overId, 100, 45, OVER_RECT),
        )
      })

      expect(targetColumnChildIds(result.current.pendingTree)).toEqual([41, 40])
    })
  })

  describe('getPointerPosition grab-point offset', () => {
    // getPointerPosition computes the true pointer viewport position by offsetting
    // the scroll-adjusted `translated` rect by the grab distance within the
    // `initial` rect: pointer = translated + (client - initial). When initial and
    // translated diverge (element dragged away from its origin) and the grab point
    // is offset from the rect origin, the arithmetic must combine them correctly —
    // otherwise the resolved direction (before/after) flips. Zero-origin rects
    // (every other test) hide this because the offsets cancel.

    it('resolves Y direction using translated + (clientY - initialTop) for an element drop', () => {
      // The DROP-time pointer is the only input to onReorder's `after`, so the
      // divergent active rects must be on the drag-END event — not just the
      // drag-over (whose pending-tree order does not feed `after`).
      //
      // initialTop=100, translatedTop=300 (dragged 200px down), clientY=110
      // (grabbed 10px below the element top). True pointer.y = 300 + (110-100) = 310.
      // Over-element rect top=300 height=220 → midpoint Y=410. 310 < 410 → 'before'
      // → after=null (target column head).
      //   Mutant `clientY + initialTop`: 300 + (110+100) = 510 → 510 > 410 → 'after'
      //   → after=element2. The asserted `after=null` distinguishes them.
      const activeRects = {
        initial: { top: 100, left: 0, width: 200, height: 50 },
        translated: { top: 300, left: 0, width: 200, height: 50 },
      }
      const element2 = createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })
      const col30 = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })],
      })
      const col31 = createColumnNode({
        id: 31,
        parent: { type: 'row', id: 20 },
        children: [element2],
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [col30, col31],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })

      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', 40)
      const overContainerId = buildDraggableId('column', 31)
      const overElementId = buildDraggableId('element', 41)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(
          makePointerDragOverEvent(
            activeId,
            overContainerId,
            100,
            110,
            {
              top: 300,
              left: 0,
              width: 200,
              height: 220,
            },
            activeRects,
          ),
        )
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(
          makePointerDragEndEvent(
            activeId,
            overElementId,
            100,
            110,
            {
              top: 300,
              left: 0,
              width: 200,
              height: 220,
            },
            activeRects,
          ),
        )
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
      const [, , after] = onReorder.mock.calls[0]
      expect(after).toBeNull()
    })

    it.each([
      {
        name: 'outer subtraction mutant: distinguished by a center between mutant and correct X',
        // initialLeft=100, translatedLeft=300, clientX=110 → true x = 300+(110-100)=310.
        // Mutant `translatedLeft - (clientX - initialLeft)` = 300-10 = 290.
        // overRect centerX=300 (left=200,width=200): correct 310 > 300 → 'after';
        // mutant 290 < 300 → 'before'. Different column order.
        overRect: { top: 0, left: 200, width: 200, height: 50 },
        expectedOrder: [31, 30],
      },
      {
        name: 'inner addition mutant: distinguished by a center between correct and mutant X',
        // True x=310. Mutant `clientX + initialLeft` = 300+(110+100)=510.
        // overRect centerX=410 (left=310,width=200): correct 310 < 410 → 'before';
        // mutant 510 > 410 → 'after'. Different column order.
        overRect: { top: 0, left: 310, width: 200, height: 50 },
        expectedOrder: [30, 31],
      },
    ])('resolves column X direction using the grab offset — $name', ({
      overRect,
      expectedOrder,
    }) => {
      // Two rows so col30 → row21 is a genuine cross-container column move.
      // row21 = [col31] only; dropping col30 before/after col31 reorders row21.
      const col30 = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })],
      })
      const col31 = createColumnNode({
        id: 31,
        parent: { type: 'row', id: 21 },
        children: [createSimpleElement({ id: 41, parent: { type: 'column', id: 31 } })],
      })
      const row20 = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [col30],
      })
      const row21 = createRowNode({
        id: 21,
        parent: { type: 'section', id: 10 },
        children: [col31],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row20, row21],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })

      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('column', 30)
      const overId = buildDraggableId('column', 31)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(
          makePointerDragOverEvent(activeId, overId, 110, 25, overRect, {
            initial: { top: 0, left: 100, width: 200, height: 50 },
            translated: { top: 0, left: 300, width: 200, height: 50 },
          }),
        )
      })

      // row21 (second row) now holds both columns in the resolved order.
      const section0 = result.current.pendingTree?.nodes[0] as ReturnType<typeof createSectionNode>
      const targetRow = section0.children?.[1] as ReturnType<typeof createRowNode>
      expect(targetRow.children?.map((c) => c.self.id)).toEqual(expectedOrder)
    })
  })

  describe('pendingActive flag (DragContext)', () => {
    it('drives pendingActive false → true on cross-container onDragOver and back to false on onDragCancel', () => {
      // GridEditor derives DragContext.pendingActive as `pendingTree !== null`
      // and provides it to block components. The hook itself owns the pendingTree
      // transition; pendingActive is that exact boolean. Observe it here.
      const { tree, element1, col2 } = buildTwoColumnTree()
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('column', col2.self.id)

      const pendingActive = () => result.current.pendingTree !== null

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      expect(pendingActive()).toBe(false)

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overId))
      })
      expect(pendingActive()).toBe(true)

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent())
      })
      expect(pendingActive()).toBe(false)
    })
  })

  describe('onDragEnd', () => {
    it('clears dragState when over is null', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      expect(result.current.dragState).not.toBeNull()

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, null))
      })

      expect(result.current.dragState).toBeNull()
      expect(onReorder).not.toHaveBeenCalled()
    })

    it('clears dragState when active.id equals over.id (no-op)', () => {
      const { tree, element1 } = buildSingleColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, activeId))
      })

      expect(result.current.dragState).toBeNull()
      expect(onReorder).not.toHaveBeenCalled()
    })

    it('calls onReorder for same-container reorder with scoped NodeRefs', () => {
      const { tree, element1, element2 } = buildSingleColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId))
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
      expect(onReorder).toHaveBeenCalledWith(
        { type: 'element', id: element1.self.id },
        { type: 'column', id: 30 },
        { type: 'element', id: element2.self.id },
        expect.any(Function),
      )
    })

    it('does not call onReorder when drop resolves to same position (no-op)', () => {
      const element = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
      const column = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [element],
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [column],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree: TreeApiResponse = createTreeApiResponse({ pageId: 1, sections: [section] })

      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', 40)
      const overId = buildDraggableId('column', 30)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId))
      })

      expect(onReorder).not.toHaveBeenCalled()
    })

    it('clears dragState even when onReorder is called', () => {
      const { tree, element1, element2 } = buildSingleColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId))
      })

      expect(result.current.dragState).toBeNull()
    })

    it('handles cross-container drop via pending tree', () => {
      const { tree, element1, col2 } = buildTwoColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overContainerId = buildDraggableId('column', col2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId))
      })
      expect(result.current.pendingTree).not.toBeNull()

      const overElementId = buildDraggableId('element', 41)
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overElementId))
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
      const [element, parent] = onReorder.mock.calls[0]
      expect(element).toEqual({ type: 'element', id: element1.self.id })
      expect(parent).toEqual({ type: 'column', id: 31 })
      expect(result.current.dragState).toBeNull()
    })

    it.each([
      {
        name: 'before (pointer above the over-element midpoint)',
        clientY: 5,
        expectedAfter: null,
      },
      {
        name: 'after (pointer below the over-element midpoint)',
        clientY: 45,
        expectedAfter: { type: 'element', id: 41 },
      },
    ])('resolves cross-container drop direction from a real PointerEvent: $name', ({
      clientY,
      expectedAfter,
    }) => {
      // Only a real PointerEvent activatorEvent makes getPointerPosition return
      // a pointer (every other test uses new Event('pointer'), which bails to
      // null and exercises only the index-based path). With initial===translated
      // active rects, getPointerPosition resolves pointer.y === clientY.
      //
      // Over element rect top=0,height=50 → midpoint Y=25. clientY=5 → 'before'
      // (after=null, head of target); clientY=45 → 'after' (after=element 41).
      const { tree, element1, element2, col2 } = buildTwoColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overContainerId = buildDraggableId('column', col2.self.id)
      const overElementId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId))
      })

      act(() => {
        result.current.dndContextProps.onDragEnd(
          makePointerDragEndEvent(activeId, overElementId, 100, clientY, {
            top: 0,
            left: 0,
            width: 200,
            height: 50,
          }),
        )
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
      const [element, parent, after] = onReorder.mock.calls[0]
      expect(element).toEqual({ type: 'element', id: element1.self.id })
      expect(parent).toEqual({ type: 'column', id: 31 })
      expect(after).toEqual(expectedAfter)
    })

    it('clears pending tree when active ID is unparseable', () => {
      const { tree } = buildSingleColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', 40)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, 'garbage'))
      })

      expect(result.current.dragState).toBeNull()
      expect(onReorder).not.toHaveBeenCalled()
    })

    it('provides clearPendingTree callback to onReorder', () => {
      const { tree, element1, element2 } = buildSingleColumnTree()
      let capturedClear: (() => void) | undefined
      const onReorder = vi.fn((_element, _parent, _after, clear) => {
        capturedClear = clear
      })
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overId = buildDraggableId('element', element2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId))
      })

      expect(capturedClear).toBeDefined()
      expect(typeof capturedClear).toBe('function')
    })
  })

  describe('onDragOver container target', () => {
    it('sets after=null when dropping into an empty cross-type container', () => {
      // Empty target column: `children.length > 0` must be FALSE so `after` is null
      // rather than dereferencing children[-1].self (which would throw). Mutants that
      // force `true`, `>= 0`, or `<= 0` evaluate the length=0 branch as true and crash.
      const moved = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
      const col1 = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [moved],
      })
      const col2 = createColumnNode({
        id: 31,
        parent: { type: 'row', id: 20 },
        children: [],
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [col1, col2],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })

      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', 40)
      const overContainerId = buildDraggableId('column', col2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId))
      })
      // Drop over the container itself — resolveDropPlacement's cross-type branch pushes
      // the active element onto `filtered`, producing an `after` determined by the pending
      // tree order.
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overContainerId))
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
      const [, parent, after] = onReorder.mock.calls[0]
      expect(parent).toEqual({ type: 'column', id: 31 })
      expect(after).toBeNull()
    })

    it('sets after to the last existing child when dropping into a non-empty cross-type container', () => {
      // Target column has [x, y, z]. Dragging element1 (from col 30) over col 31 as a
      // container should position active AFTER the last existing child. Mutants that
      // force the `after = …` ternary to `false`/`<= 0` return null and place active at
      // the head, changing pending-tree order.
      const moved = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
      const x = createSimpleElement({ id: 50, parent: { type: 'column', id: 31 } })
      const y = createSimpleElement({ id: 51, parent: { type: 'column', id: 31 } })
      const z = createSimpleElement({ id: 52, parent: { type: 'column', id: 31 } })
      const col1 = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [moved],
      })
      const col2 = createColumnNode({
        id: 31,
        parent: { type: 'row', id: 20 },
        children: [x, y, z],
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [col1, col2],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })

      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('element', 40)
      const overContainerId = buildDraggableId('column', col2.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId))
      })

      // Verify the pending tree places active at the END of the target column.
      const pending = result.current.pendingTree
      expect(pending).not.toBeNull()
      const targetCol = ((pending?.nodes[0] as typeof section).children?.[0] as typeof row)
        .children?.[1]
      expect((targetCol as typeof col2).children?.map((c) => c.self.id)).toEqual([50, 51, 52, 40])
    })
  })

  describe('placement=null cleanup', () => {
    it('clears the pending tree when resolveDropPlacement returns null after a cross-container drag-over', () => {
      // Trigger pendingTree via cross-container drag-over, then drop over a parseable but
      // non-existent element ID. resolveDropPlacement can't resolve overKey in the effective
      // maps → returns null → the else branch must call pending.clear(). If that branch is
      // removed, pendingTree leaks after drag end.
      const { tree, element1, col2 } = buildTwoColumnTree()
      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', element1.self.id)
      const overContainerId = buildDraggableId('column', col2.self.id)
      const missingOverId = buildDraggableId('element', 999)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent(activeId, overContainerId))
      })
      expect(result.current.pendingTree).not.toBeNull()

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, missingOverId))
      })

      expect(onReorder).not.toHaveBeenCalled()
      expect(result.current.pendingTree).toBeNull()
    })
  })

  describe('sourceIndex findIndex', () => {
    it('calls onReorder when moving a mid-column element before the first element', () => {
      // Column has [A, B, C] at indexes 0, 1, 2. Dragging B over A should result in
      // [B, A, C] (overIndex=0, sourceIndex=1). The no-op detector compares sourceIndex
      // to overIndex — with the real predicate they differ (1 !== 0) so onReorder fires.
      // A mutant that forces sourceIndex to 0 (`findIndex((n) => true)`) would make
      // 0 === 0 → no-op → onReorder skipped.
      const a = createSimpleElement({ id: 40, parent: { type: 'column', id: 30 } })
      const b = createSimpleElement({ id: 41, parent: { type: 'column', id: 30 } })
      const c = createSimpleElement({ id: 42, parent: { type: 'column', id: 30 } })
      const column = createColumnNode({
        id: 30,
        parent: { type: 'row', id: 20 },
        children: [a, b, c],
      })
      const row = createRowNode({
        id: 20,
        parent: { type: 'section', id: 10 },
        children: [column],
      })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })

      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('element', b.self.id)
      const overId = buildDraggableId('element', a.self.id)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })
      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent(activeId, overId))
      })

      expect(onReorder).toHaveBeenCalledTimes(1)
    })
  })

  describe('polymorphic collision regression', () => {
    it('handles section drag when section ID equals page ID', () => {
      // Fresh-DB scenario: page id=1, first section id=1.
      const section1 = createSectionNode({
        id: 1,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      })
      const section2 = createSectionNode({
        id: 2,
        parent: { type: 'page', id: 1 },
        rowCount: 0,
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section1, section2] })

      const onReorder = vi.fn()
      const { result } = renderDndHook({ tree, onReorder })

      const activeId = buildDraggableId('section', 1)
      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      // Drag must register — before the fix this would silently abort because
      // the sibling lookup returned the wrong entry.
      expect(result.current.dragState).not.toBeNull()
      expect(result.current.dragState?.activeType).toBe('section')
      expect(result.current.dragState?.activeNode.self.id).toBe(1)
    })
  })

  describe('drag-start primes collision detection with the source siblings', () => {
    // handleDragStart records the active element's OTHER container siblings into
    // pending.sourceContainerItemsRef. That set is consumed by the hook's own
    // collision detection (dndContextProps.collisionDetection) to (a) restrict
    // sibling matching to same-container siblings and (b) gate the
    // "pointer-inside-source-sibling" short-circuit on `sourceItems.size > 0`.
    // The set is observable only by driving that public collision function, so
    // these two cases verify it is built with the right membership.

    // Active row sits above the target sibling; the pointer is inside the
    // sibling rect but has NOT crossed the centerCrossing threshold. With the
    // source set populated, the guard short-circuits to [] (ghost-jump
    // prevention). With the set empty, the guard is skipped and detection falls
    // through to the parent section.
    const SIBLING_RECT = makeDomRect(50, 275, 200, 100) // y 275-375, center 325
    const PARENT_RECT = makeDomRect(0, 0, 800, 600)

    function buildRowCollisionArgs(activeRowId: string, siblingRowId: string) {
      const sibling = createDroppableWithRect(siblingRowId, {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const parent = createDroppable('section-10')
      const collisionRect = makeDomRect(100, 265, 100, 50) // currentCY 290
      const initialRect = makeDomRect(100, 75, 100, 50) // initialCY 100 (above target)
      return {
        active: {
          id: activeRowId,
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [sibling, parent],
        droppableRects: new Map<string | number, ClientRect>([
          [siblingRowId, SIBLING_RECT],
          ['section-10', PARENT_RECT],
        ]),
        // Pointer (150, 290) is inside the sibling rect but thresholdY = 300, so
        // 290 has not crossed → centerCrossing yields no sibling hit.
        pointerCoordinates: { x: 150, y: 290 },
      }
    }

    it('excludes the active element so the source set holds only the real siblings (loop body runs)', () => {
      // section-10 has [row-1 (dragged), row-2 (sibling)]. After drag-start the
      // source set must be {row-2}. Driving collision with the pointer inside
      // row-2 (no threshold crossing) makes the pointer-inside-source-sibling
      // guard fire → []. If the set-building loop never adds row-2 (empty set),
      // `sourceItems.size > 0` is false, the guard is skipped, and detection
      // returns the parent section-10 instead.
      const row1 = createRowNode({ id: 1, parent: { type: 'section', id: 10 }, children: [] })
      const row2 = createRowNode({ id: 2, parent: { type: 'section', id: 10 }, children: [] })
      const section = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row1, row2],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('row', 1)
      const siblingId = buildDraggableId('row', 2)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      const collisions = result.current.dndContextProps.collisionDetection(
        buildRowCollisionArgs(activeId, siblingId) as never,
      )

      // Guard fired: empty result, NOT the parent fallback.
      expect(collisions).toEqual([])
    })

    it('excludes only the active id, keeping source depletion empty so ALL siblings are scanned', () => {
      // Source depletion: section-10 holds ONLY row-1 (the dragged row); a SECOND
      // container section-20 holds row-9 (same type, different container). The
      // correct loop adds nothing for row-1 → empty source set → `size > 0` is
      // false → detection scans ALL same-type siblings, letting centerCrossing
      // detect the cross-container row-9 (the depletion fallback).
      //
      // A mutant that drops the `sibling.nodeKey !== parsed.key` guard would add
      // row-1's OWN id → size 1 → detection filters same-type siblings to the
      // source set, which excludes row-9 → empty → no sibling hit → it returns
      // the parent section instead of row-9.
      const row1 = createRowNode({ id: 1, parent: { type: 'section', id: 10 }, children: [] })
      const row9 = createRowNode({ id: 9, parent: { type: 'section', id: 20 }, children: [] })
      const section10 = createSectionNode({
        id: 10,
        parent: { type: 'page', id: 1 },
        children: [row1],
      })
      const section20 = createSectionNode({
        id: 20,
        parent: { type: 'page', id: 1 },
        children: [row9],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section10, section20] })
      const { result } = renderDndHook({ tree })

      const activeId = buildDraggableId('row', 1)
      const targetSiblingId = buildDraggableId('row', 9)

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent(activeId))
      })

      // row-9 rect center 325; thresholdY = 275 + 25 = 300. collisionRect center
      // currentCY = 310 (>= 300) → crosses downward → centerCrossing hits row-9
      // when row-9 is actually scanned. Pointer (150, 310) is within the overlap
      // gate of row-9.
      const targetRow = createDroppableWithRect(targetSiblingId, {
        left: 50,
        top: 275,
        width: 200,
        height: 100,
      })
      const parent = createDroppable('section-20')
      const collisionRect = makeDomRect(100, 285, 100, 50) // currentCY 310
      const initialRect = makeDomRect(100, 75, 100, 50) // initialCY 100 (above target)

      const collisions = result.current.dndContextProps.collisionDetection({
        active: {
          id: activeId,
          rect: { current: { initial: initialRect, translated: collisionRect } },
          data: { current: undefined },
        },
        collisionRect,
        droppableContainers: [targetRow, parent],
        droppableRects: new Map<string | number, ClientRect>([
          [targetSiblingId, makeDomRect(50, 275, 200, 100)],
          ['section-20', PARENT_RECT],
        ]),
        pointerCoordinates: { x: 150, y: 310 },
      } as never)

      // Empty source set → ALL siblings scanned → centerCrossing detects row-9.
      expect(collisions).toHaveLength(1)
      expect(String(collisions[0].id)).toBe(targetSiblingId)
    })
  })
})
