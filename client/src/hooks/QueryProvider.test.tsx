import { useQueryClient } from '@tanstack/react-query'
import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import GridQueryProvider from './QueryProvider'

function Wrapper({ children }: { children: ReactNode }) {
  return <GridQueryProvider>{children}</GridQueryProvider>
}

describe('GridQueryProvider', () => {
  it('should provide a QueryClient to children', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    expect(result.current).toBeDefined()
  })

  it('should configure retry: false', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    const defaults = result.current.getDefaultOptions()
    expect(defaults.queries?.retry).toBe(false)
  })

  it('should set gcTime to 5 minutes (5 * 60_000 ms)', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    const defaults = result.current.getDefaultOptions()
    expect(defaults.queries?.gcTime).toBe(300_000)
  })

  it('should disable refetchOnWindowFocus', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    const defaults = result.current.getDefaultOptions()
    expect(defaults.queries?.refetchOnWindowFocus).toBe(false)
  })

  it('should create isolated QueryClient per mount', () => {
    const { result: first } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })
    const { result: second } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    expect(first.current).not.toBe(second.current)
  })
})
