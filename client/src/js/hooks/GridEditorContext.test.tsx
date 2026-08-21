import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import { renderExpectingError } from '@/testing/renderExpectingError'
import { GridEditorProvider, useGridEditorContext } from './GridEditorContext'

describe('useGridEditorContext', () => {
  it('returns pageId, zone and rootType from provider', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return (
        <GridEditorProvider value={{ pageId: 42, zone: 'sidebar', rootType: 'sharedBlock' }}>
          {children}
        </GridEditorProvider>
      )
    }

    const { result } = renderHook(() => useGridEditorContext(), {
      wrapper: Wrapper,
    })

    expect(result.current.pageId).toBe(42)
    expect(result.current.zone).toBe('sidebar')
    expect(result.current.rootType).toBe('sharedBlock')
  })

  it('throws a clear error when used outside a provider', () => {
    function Probe() {
      useGridEditorContext()
      return null
    }

    const error = renderExpectingError(<Probe />)
    expect(error.message).toBe('useGridEditorContext must be used within a GridEditorProvider')
  })
})
