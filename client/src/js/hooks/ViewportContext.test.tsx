import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { resetActiveViewportStore, setActiveViewport } from '@/state/activeViewport'
import { useViewportContext } from './ViewportContext'

describe('useViewportContext', () => {
  afterEach(() => {
    resetActiveViewportStore()
  })

  it('reads the active viewport from the shared store', () => {
    setActiveViewport('lg')

    const { result } = renderHook(() => useViewportContext())

    expect(result.current.activeViewport).toBe('lg')
  })

  it('reflects store updates made through the returned setter', () => {
    setActiveViewport('md')

    const { result } = renderHook(() => useViewportContext())

    act(() => {
      result.current.setActiveViewport('xl')
    })

    expect(result.current.activeViewport).toBe('xl')
  })
})
