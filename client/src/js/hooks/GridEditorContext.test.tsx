import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import { GridEditorProvider, useGridEditorContext } from './GridEditorContext'

describe('useGridEditorContext', () => {
  it('returns pageId and zone from provider', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return (
        <GridEditorProvider value={{ pageId: 42, zone: 'sidebar' }}>{children}</GridEditorProvider>
      )
    }

    const { result } = renderHook(() => useGridEditorContext(), {
      wrapper: Wrapper,
    })

    expect(result.current.pageId).toBe(42)
    expect(result.current.zone).toBe('sidebar')
  })

  it('throws a clear error when used outside a provider', () => {
    const prevError = console.error
    console.error = () => {}
    try {
      expect(() => renderHook(() => useGridEditorContext())).toThrow(
        'useGridEditorContext must be used within a GridEditorProvider',
      )
    } finally {
      console.error = prevError
    }
  })
})
