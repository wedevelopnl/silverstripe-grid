import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useDuplicateAction } from '@/hooks/useDuplicateAction'
import { createSimpleElement, resetIdCounter } from '@/testing/factories'
import { getFetchCalls, mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper } from '@/testing/renderWithProviders'
import type { ElementNode } from '@/types/elements'
import { showToast } from '@/utils/toast'

// Spy on the toast helper so the error path can be asserted directly. The shared
// mutation-level onError (useStandardMutationOptions) is the ONLY toast source —
// the hook itself passes no per-call onError, so a single failure must fire
// exactly one toast.
vi.mock('@/utils/toast', () => ({
  showToast: vi.fn(),
}))

function renderDuplicateAction(node: ElementNode) {
  const { wrapper } = createProviderWrapper()
  return renderHook(() => useDuplicateAction(node), { wrapper })
}

describe('useDuplicateAction', () => {
  beforeEach(() => {
    resetIdCounter()
    vi.mocked(showToast).mockClear()
  })

  it('should return null action when canCreate is false', () => {
    const node = createSimpleElement({ canCreate: false })
    const { result } = renderDuplicateAction(node)

    expect(result.current.action).toBeNull()
  })

  it('should return action when canCreate is true', () => {
    const node = createSimpleElement({ canCreate: true })
    const { result } = renderDuplicateAction(node)

    expect(result.current.action).not.toBeNull()
    expect(result.current.action?.key).toBe('duplicate')
  })

  // Duplicating in place reuses the original's parent, so a block's root would
  // gain a sibling root — and getRootElement() returns the first, leaving the
  // copy as invisible orphaned content. The server refuses it too.
  it('offers no duplicate on a block root, whose parent is the block itself', () => {
    const node = createSimpleElement({ canCreate: true, parent: { type: 'sharedBlock', id: 4 } })
    const { result } = renderDuplicateAction(node)

    expect(result.current.action).toBeNull()
  })

  it('labels the action "Duplicate"', () => {
    const node = createSimpleElement({ canCreate: true })
    const { result } = renderDuplicateAction(node)

    expect(result.current.action?.label).toBe('Duplicate')
  })

  it('should trigger duplicate mutation with correct URL on action', async () => {
    mockFetchSuccess({})
    const node = createSimpleElement({ id: 42 })
    const { result } = renderDuplicateAction(node)

    act(() => {
      result.current.action?.onAction()
    })

    await waitFor(() => {
      expect(getFetchCalls().length).toBeGreaterThan(0)
    })

    const [url, init] = getFetchCalls()[0]
    expect(url).toContain('/api/duplicate')
    expect(init?.method).toBe('POST')
  })

  it('fires exactly one error toast when duplication fails', async () => {
    // Real fetch-backed failure drives the shared mutation onError. Asserting a
    // SINGLE toast guards against a per-call onError being reintroduced — that
    // would double the toast (the original bug this test locks down).
    mockFetchError(500)
    const node = createSimpleElement({ id: 42 })
    const { result } = renderDuplicateAction(node)

    act(() => {
      result.current.action?.onAction()
    })

    await waitFor(() => {
      expect(showToast).toHaveBeenCalledWith('API error 500: Error 500')
    })
    expect(showToast).toHaveBeenCalledTimes(1)
  })
})
