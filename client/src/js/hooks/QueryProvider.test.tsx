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

  it('should configure the CMS query defaults (no retry, 5-minute gcTime, no focus refetch)', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    })

    expect(result.current.getDefaultOptions().queries).toMatchObject({
      retry: false,
      gcTime: 300_000,
      refetchOnWindowFocus: false,
    })
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
