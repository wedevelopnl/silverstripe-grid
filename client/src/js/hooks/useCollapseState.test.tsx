import { act, render, renderHook, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { beforeEach, describe, expect, it } from 'vitest'
import { renderExpectingError } from '@/testing/renderExpectingError'
import { NodeIdentity } from '@/types/identity'
import {
  CollapseContext,
  type CollapseState,
  useCollapse,
  useCollapseState,
} from './useCollapseState'

// localStorage is provided globally and cleared before each test (see
// vitest.setup.ts), so tests read and write `localStorage` directly.
let areaId: number

beforeEach(() => {
  areaId = Math.floor(Math.random() * 1_000_000)
})

describe('useCollapseState', () => {
  it('starts with an empty collapse set', () => {
    const { result } = renderHook(() => useCollapseState(areaId))
    expect(result.current.isCollapsed(NodeIdentity.toKey('section', 1))).toBe(false)
  })

  it('toggle(key) adds the key to the collapsed set', () => {
    const { result } = renderHook(() => useCollapseState(areaId))
    const key = NodeIdentity.toKey('section', 5)

    act(() => {
      result.current.toggle(key)
    })

    expect(result.current.isCollapsed(key)).toBe(true)
  })

  it('toggle(key) twice removes the key', () => {
    const { result } = renderHook(() => useCollapseState(areaId))
    const key = NodeIdentity.toKey('row', 10)

    act(() => {
      result.current.toggle(key)
    })
    expect(result.current.isCollapsed(key)).toBe(true)

    act(() => {
      result.current.toggle(key)
    })
    expect(result.current.isCollapsed(key)).toBe(false)
  })

  it(`persists collapsed keys to localStorage under grid:collapsed:${areaId}`, () => {
    const mock = localStorage
    const { result } = renderHook(() => useCollapseState(areaId))
    const key = NodeIdentity.toKey('column', 7)

    act(() => {
      result.current.toggle(key)
    })

    const raw = mock.getItem(`grid:collapsed:${areaId}`)
    expect(raw).not.toBeNull()
    expect(JSON.parse(raw as string)).toEqual([key])
  })

  it('restores collapsed keys from localStorage on mount', () => {
    const mock = localStorage
    const key1 = NodeIdentity.toKey('section', 1)
    const key2 = NodeIdentity.toKey('row', 2)
    mock.setItem(`grid:collapsed:${areaId}`, JSON.stringify([key1, key2]))

    const { result } = renderHook(() => useCollapseState(areaId))

    expect(result.current.isCollapsed(key1)).toBe(true)
    expect(result.current.isCollapsed(key2)).toBe(true)
    expect(result.current.isCollapsed(NodeIdentity.toKey('row', 99))).toBe(false)
  })

  it.each<[string, string]>([
    ['corrupt JSON', '{not-json'],
    ['non-array JSON', '{"foo":"bar"}'],
    ['old numeric format', '[1, 5, 10]'],
  ])('drops malformed localStorage payload (%s) silently', (_label, raw) => {
    const mock = localStorage
    mock.setItem(`grid:collapsed:${areaId}`, raw)

    const { result } = renderHook(() => useCollapseState(areaId))

    expect(result.current.isCollapsed(NodeIdentity.toKey('section', 1))).toBe(false)
    expect(result.current.isCollapsed(NodeIdentity.toKey('row', 10))).toBe(false)
  })

  it('keeps only valid NodeKey entries from a mixed localStorage payload', () => {
    const mock = localStorage
    const valid = NodeIdentity.toKey('section', 1)
    mock.setItem(`grid:collapsed:${areaId}`, JSON.stringify([valid, 99, 'not-a-key', 'row-abc']))

    const { result } = renderHook(() => useCollapseState(areaId))

    expect(result.current.isCollapsed(valid)).toBe(true)
    expect(result.current.isCollapsed(NodeIdentity.toKey('row', 99))).toBe(false)
    // The invalid raw entries must be filtered out of the set — looking them
    // up by their literal stored value must report not-collapsed. Without the
    // .filter()'s string-typeof + fromKey guard, these survive into the set
    // and would read back as collapsed.
    expect(result.current.isCollapsed('not-a-key' as never)).toBe(false)
    expect(result.current.isCollapsed('row-abc' as never)).toBe(false)
    expect(result.current.isCollapsed(99 as never)).toBe(false)
  })

  it('keeps separate state for different areaIds', () => {
    const areaA = areaId
    const areaB = areaId + 1
    const key = NodeIdentity.toKey('section', 1)

    const { result: resultA } = renderHook(() => useCollapseState(areaA))
    const { result: resultB } = renderHook(() => useCollapseState(areaB))

    act(() => {
      resultA.current.toggle(key)
    })

    expect(resultA.current.isCollapsed(key)).toBe(true)
    expect(resultB.current.isCollapsed(key)).toBe(false)
  })
})

describe('useCollapse (context consumer)', () => {
  function Probe() {
    const collapse = useCollapse()
    return (
      <div
        data-testid="probe"
        data-is-section-collapsed={String(collapse.isCollapsed('section-1'))}
      />
    )
  }

  it('throws a clear error when used outside a provider', () => {
    const error = renderExpectingError(<Probe />)
    expect(error.message).toMatch(/useCollapse must be used within a <CollapseContext.Provider>/)
  })

  it('returns the provider-supplied CollapseState', () => {
    const mockState: CollapseState = {
      isCollapsed: (key) => key === 'section-1',
      toggle: () => {
        /* no-op */
      },
    }

    function Wrapper({ children }: { children: ReactNode }) {
      return <CollapseContext.Provider value={mockState}>{children}</CollapseContext.Provider>
    }

    render(
      <Wrapper>
        <Probe />
      </Wrapper>,
    )

    expect(screen.getByTestId('probe')).toHaveAttribute('data-is-section-collapsed', 'true')
  })
})
