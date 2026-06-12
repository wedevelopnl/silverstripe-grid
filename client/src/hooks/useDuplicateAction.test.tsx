import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { useDuplicateAction } from '@/hooks/useDuplicateAction'
import { createSimpleElement, resetIdCounter } from '@/testing/factories'
import { getFetchCalls, mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper } from '@/testing/renderWithProviders'
import type { ElementNode } from '@/types/elements'

function renderDuplicateAction(node: ElementNode) {
  const { wrapper } = createProviderWrapper()
  return renderHook(() => useDuplicateAction(node), { wrapper })
}

describe('useDuplicateAction', () => {
  beforeEach(() => {
    resetIdCounter()
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

  it('should show toast when mutation fails', async () => {
    mockFetchError(500, { message: 'Duplicate failed' })
    const dispatch = vi.fn()
    window.ss!.store = { dispatch }

    const node = createSimpleElement({ id: 42 })
    const { result } = renderDuplicateAction(node)

    act(() => {
      result.current.action?.onAction()
    })

    await waitFor(() => {
      expect(dispatch).toHaveBeenCalledWith(
        expect.objectContaining({
          type: 'DISPLAY_TOAST',
          payload: expect.objectContaining({ type: 'error' }),
        }),
      )
    })
  })
})
