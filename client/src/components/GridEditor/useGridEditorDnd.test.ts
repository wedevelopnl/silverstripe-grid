import { act, renderHook } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createSectionNode, createTreeApiResponse, resetIdCounter } from '@/testing/factories'

// Capture the onReorder callback handed to the (mocked) useDragAndDrop so the
// test can invoke it directly without a real drag.
const capturedOnReorder: { current: null | ((...args: never[]) => void) } = { current: null }

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragAndDrop: (opts: { onReorder: (...args: never[]) => void }) => {
    capturedOnReorder.current = opts.onReorder
    return { dndContextProps: {}, dragState: null, pendingTree: null }
  },
}))

const reorderMutate = vi.fn()
vi.mock('@/hooks/useElementMutations', () => ({
  useReorderElement: () => ({ mutate: reorderMutate }),
}))

import { useGridEditorDnd } from './useGridEditorDnd'

describe('useGridEditorDnd', () => {
  beforeEach(() => {
    capturedOnReorder.current = null
    reorderMutate.mockClear()
    resetIdCounter()
  })

  it('derives sections and section ids from the tree', () => {
    const tree = createTreeApiResponse({
      pageId: 1,
      sections: [
        createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'A' }),
        createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'B' }),
      ],
    })
    const { result } = renderHook(() => useGridEditorDnd(tree, 1, 'main'))
    expect(result.current.sections).toHaveLength(2)
    expect(result.current.sectionIds).toHaveLength(2)
  })

  it('forwards the loaded tree to the reorder mutation', () => {
    const tree = createTreeApiResponse({
      pageId: 1,
      sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'A' })],
    })
    renderHook(() => useGridEditorDnd(tree, 1, 'main'))
    const clear = vi.fn()
    act(() => {
      capturedOnReorder.current?.(
        { type: 'section', id: 10 } as never,
        { type: 'page', id: 1 } as never,
        null as never,
        clear as never,
      )
    })
    expect(reorderMutate).toHaveBeenCalledTimes(1)
    const call = reorderMutate.mock.calls[0][0]
    expect(call.tree).toBe(tree)
    expect(call.clearPendingTree).toBe(clear)
  })

  it('does not fire the mutation while the tree is undefined', () => {
    renderHook(() => useGridEditorDnd(undefined, 1, 'main'))
    act(() => {
      capturedOnReorder.current?.({} as never, {} as never, null as never, vi.fn() as never)
    })
    expect(reorderMutate).not.toHaveBeenCalled()
  })
})
