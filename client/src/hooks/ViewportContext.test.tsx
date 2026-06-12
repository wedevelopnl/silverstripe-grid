import { act, renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import { useViewportContext, ViewportProvider } from './ViewportContext'

describe('useViewportContext', () => {
  it('initializes from initialViewport prop', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return <ViewportProvider initialViewport="lg">{children}</ViewportProvider>
    }

    const { result } = renderHook(() => useViewportContext(), {
      wrapper: Wrapper,
    })

    expect(result.current.activeViewport).toBe('lg')
  })

  it('falls back to default viewport when no prop given', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return <ViewportProvider>{children}</ViewportProvider>
    }

    const { result } = renderHook(() => useViewportContext(), {
      wrapper: Wrapper,
    })

    expect(result.current.activeViewport).toBe('md')
  })

  it('updates viewport via setter', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return <ViewportProvider initialViewport="sm">{children}</ViewportProvider>
    }

    const { result } = renderHook(() => useViewportContext(), {
      wrapper: Wrapper,
    })

    act(() => {
      result.current.setActiveViewport('xl')
    })

    expect(result.current.activeViewport).toBe('xl')
  })
})
