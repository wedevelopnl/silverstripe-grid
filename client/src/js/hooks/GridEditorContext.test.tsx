import { renderHook } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import { renderExpectingError } from '@/testing/renderExpectingError'
import type { EditorRoot } from '@/types/editorRoot'
import { GridEditorProvider, useEditorRoot, usePageRoot } from './GridEditorContext'

function wrapperFor(root: EditorRoot) {
  return function Wrapper({ children }: { children: ReactNode }) {
    return <GridEditorProvider root={root}>{children}</GridEditorProvider>
  }
}

describe('useEditorRoot', () => {
  it('returns the page root the provider was given', () => {
    const { result } = renderHook(() => useEditorRoot(), {
      wrapper: wrapperFor({ kind: 'page', pageId: 42, zone: 'sidebar' }),
    })

    expect(result.current).toEqual({ kind: 'page', pageId: 42, zone: 'sidebar' })
  })

  it('returns the block root the provider was given', () => {
    const { result } = renderHook(() => useEditorRoot(), {
      wrapper: wrapperFor({ kind: 'sharedBlock', blockId: 9 }),
    })

    expect(result.current).toEqual({ kind: 'sharedBlock', blockId: 9 })
  })

  it('throws a clear error when used outside a provider', () => {
    function Probe() {
      useEditorRoot()
      return null
    }

    const error = renderExpectingError(<Probe />)
    expect(error.message).toBe('useEditorRoot must be used within a GridEditorProvider')
  })
})

describe('usePageRoot', () => {
  it('narrows to the page root when the editor is rooted at a page', () => {
    const { result } = renderHook(() => usePageRoot(), {
      wrapper: wrapperFor({ kind: 'page', pageId: 7, zone: 'main', version: 3 }),
    })

    expect(result.current).toEqual({ kind: 'page', pageId: 7, zone: 'main', version: 3 })
  })

  it('throws rather than degrade when the editor is rooted at a shared block', () => {
    function Probe() {
      usePageRoot()
      return null
    }

    const error = renderExpectingError(
      <GridEditorProvider root={{ kind: 'sharedBlock', blockId: 9 }}>
        <Probe />
      </GridEditorProvider>,
    )
    expect(error.message).toBe('usePageRoot: this editor is rooted at a shared block, not a page')
  })
})
