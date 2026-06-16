import { renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createCollapseStateStub, createProviderWrapper } from '@/testing/renderWithProviders'
import type { NodeKey } from '@/types/identity'
import { useElementCollapse } from './useElementCollapse'

const KEY = 'section:1' as NodeKey

describe('useElementCollapse', () => {
  it('reports isCollapsed=false when the node is not in the collapsed set', () => {
    const { wrapper } = createProviderWrapper()
    const { result } = renderHook(() => useElementCollapse(KEY), { wrapper })
    expect(result.current.isCollapsed).toBe(false)
  })

  it('reports isCollapsed=true when the node is in the collapsed set', () => {
    const { wrapper } = createProviderWrapper({ collapsedKeys: [KEY] })
    const { result } = renderHook(() => useElementCollapse(KEY), { wrapper })
    expect(result.current.isCollapsed).toBe(true)
  })

  it('toggles the collapse state with the node key when onToggle is called', () => {
    const collapseState = createCollapseStateStub()
    const { wrapper } = createProviderWrapper({ collapseState })
    const { result } = renderHook(() => useElementCollapse(KEY), { wrapper })
    result.current.onToggle()
    expect(collapseState.toggle).toHaveBeenCalledWith(KEY)
  })

  it('keeps onToggle referentially stable across re-renders for the same key', () => {
    const { wrapper } = createProviderWrapper()
    const { result, rerender } = renderHook(() => useElementCollapse(KEY), { wrapper })
    const first = result.current.onToggle
    rerender()
    expect(result.current.onToggle).toBe(first)
  })
})
