import type { DragCancelEvent, DragEndEvent, DragOverEvent, DragStartEvent } from '@dnd-kit/core'
import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createActive, createOver } from '@/testing/dndHelpers'
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
import { useDragAndDrop } from './useDragAndDrop'

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
})
