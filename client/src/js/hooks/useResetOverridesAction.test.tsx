import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
} from '@/testing/factories'
import { flushObservers } from '@/testing/flush'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper, createTestQueryClient } from '@/testing/renderWithProviders'
import type { ColumnNode, ViewportSettings } from '@/types/elements'
import { queryKeys } from './queryKeys'
import { type ResetScopeOption, useResetOverridesAction } from './useResetOverridesAction'

/**
 * One column per unit, each overriding exactly the named viewport. Because
 * every column here deviates at a single viewport, the derived total equals the
 * sum of the per-viewport counts — the multi-viewport case is built by hand.
 */
function columnsFromCounts(counts: Record<string, number>): ColumnNode[] {
  const override: ViewportSettings = { width: 6, offset: 0, visible: true }
  const defaults: ViewportSettings = { width: 12, offset: 0, visible: true }
  const columns: ColumnNode[] = []

  for (const [viewport, n] of Object.entries(counts)) {
    for (let i = 0; i < n; i++) {
      columns.push(
        createColumnNode({
          gridSettings: { default: defaults, overrides: { [viewport]: override } },
          children: [],
        }),
      )
    }
  }

  return columns
}

function setupWithTree(columns: ColumnNode[], viewport = 'md') {
  const pageId = 1
  const zone = 'main'
  const queryClient = createTestQueryClient()

  const section = createSectionNode({
    parent: { type: 'page', id: pageId },
    children: [createRowNode({ children: columns })],
  })
  queryClient.setQueryData(
    queryKeys.elementTree.byPage(pageId, zone),
    createTreeApiResponse({ pageId, sections: [section] }),
  )

  const { wrapper } = createProviderWrapper({
    root: { kind: 'page', pageId, zone },
    viewport,
    queryClient,
  })

  return { wrapper, queryClient }
}

function setupWithOverrides(overrideCounts: Record<string, number>, viewport = 'md') {
  return setupWithTree(columnsFromCounts(overrideCounts), viewport)
}

/** The scope entry for a viewport label, so tests read by name not by index. */
function scopeNamed(options: readonly ResetScopeOption[], label: string): ResetScopeOption {
  const found = options.find((option) => option.label === label)
  if (!found) {
    throw new Error(`no reset scope labelled "${label}" in [${options.map((o) => o.label)}]`)
  }
  return found
}

async function findResetCall() {
  return await waitFor(() => {
    const call = getFetchCalls().find(([url]) =>
      (url as string).includes('/api/resetGridSettingsOverrides'),
    )
    expect(call).toBeDefined()
    return call!
  })
}

describe('useResetOverridesAction', () => {
  describe('scope options', () => {
    it('still offers the aggregate scope, inert, when nothing is overridden', () => {
      // Permanent so the destructive scope keeps one position rather than
      // appearing and vanishing with the page's contents.
      const { wrapper } = setupWithOverrides({})

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options).toMatchObject([
        { viewport: null, label: 'All viewports', count: 0, disabled: true },
      ])
    })

    it('lists only the viewports something actually overrides, with their counts', () => {
      const { wrapper } = setupWithOverrides({ xs: 2, lg: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options).toMatchObject([
        { viewport: 'xs', label: 'Extra small', count: 2 },
        { viewport: 'lg', label: 'Large', count: 1 },
        { viewport: null, label: 'All viewports', count: 3 },
      ])
    })

    it('carries the unit on each count rather than a bare number', () => {
      // The scopes overlap — a column overridden at both viewports is counted
      // by each row and once by the aggregate — so naked numerals would read
      // as a sum that does not add up.
      const { wrapper } = setupWithOverrides({ xs: 2, lg: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options.map((o) => o.countLabel)).toEqual([
        '2 columns',
        '1 column',
        '3 columns',
      ])
    })

    it('phrases each scope as a sentence for the menu row that shows only a number', () => {
      // Both menus that render these read `actionLabel` for the accessible
      // name; the visible row is "Large" and a bare "1" in two columns.
      const { wrapper } = setupWithOverrides({ xs: 2, lg: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options.map((o) => o.actionLabel)).toEqual([
        'Reset Extra small, 2 columns',
        'Reset Large, 1 column',
        'Reset All viewports, 3 columns',
      ])
    })

    it('orders viewport scopes by the adapter, not by count or discovery', () => {
      // Seeded lg-before-xs so a fixture-order implementation fails here.
      const { wrapper } = setupWithOverrides({ lg: 1, xs: 1, sm: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options.map((o) => o.viewport)).toEqual(['xs', 'sm', 'lg', null])
    })

    it('keeps "all viewports" alongside a single overridden viewport', () => {
      const { wrapper } = setupWithOverrides({ lg: 3 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options).toMatchObject([
        { viewport: 'lg', label: 'Large', count: 3, disabled: false },
        { viewport: null, label: 'All viewports', count: 3, disabled: false },
      ])
    })

    it('reaches overrides stored against viewports the adapter no longer declares', () => {
      // Switching adapter orphans the keys the previous one wrote: no
      // per-viewport row can name them, because that list is built from the
      // adapter's own viewports. The aggregate is the only scope that clears
      // them, and the API clears by absence of a viewport, not by key.
      const { wrapper } = setupWithOverrides({ 'legacy-xs': 2 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options).toMatchObject([
        { viewport: null, label: 'All viewports', count: 2, disabled: false },
      ])
    })

    it('counts a column once in the total however many viewports it overrides', () => {
      // One column deviating at two viewports: each viewport scope clears it,
      // and so does "all", but "all" must not double-count it into 2.
      const override: ViewportSettings = { width: 6, offset: 0, visible: true }
      const { wrapper } = setupWithTree([
        createColumnNode({
          gridSettings: {
            default: { width: 12, offset: 0, visible: true },
            overrides: { xs: override, lg: override },
          },
          children: [],
        }),
      ])

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.options).toMatchObject([
        { viewport: 'xs', label: 'Extra small', count: 1 },
        { viewport: 'lg', label: 'Large', count: 1 },
        { viewport: null, label: 'All viewports', count: 1 },
      ])
    })

    // Scope must not track the selected viewport — "reset everything" has to
    // stay reachable whichever layout the author is editing.
    it.each(['xs', 'md', 'xxl'])(
      'offers the same scopes regardless of the active viewport (%s)',
      (activeViewport) => {
        const { wrapper } = setupWithOverrides({ xs: 2, lg: 1 }, activeViewport)

        const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

        expect(result.current.options).toMatchObject([
          { viewport: 'xs', label: 'Extra small', count: 2 },
          { viewport: 'lg', label: 'Large', count: 1 },
          { viewport: null, label: 'All viewports', count: 3 },
        ])
      },
    )
  })

  describe('confirmation dialog', () => {
    it('has no dialog until a scope is requested', () => {
      const { wrapper } = setupWithOverrides({ lg: 2 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })

      expect(result.current.dialog).toBeNull()
    })

    it('titles and phrases the dialog for a viewport scope', () => {
      const { wrapper } = setupWithOverrides({ lg: 3 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'Large'))
      })

      expect(result.current.dialog).toEqual({
        title: 'Reset Large overrides',
        message: 'Reset overrides for 3 columns on Large?',
      })
    })

    it('titles and phrases the dialog for the all-viewports scope', () => {
      const { wrapper } = setupWithOverrides({ xs: 2, lg: 3 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'All viewports'))
      })

      expect(result.current.dialog).toEqual({
        title: 'Reset all overrides',
        message: 'Reset all viewport overrides across 5 columns?',
      })
    })

    it('uses the singular noun for a one-column viewport scope', () => {
      const { wrapper } = setupWithOverrides({ lg: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'Large'))
      })

      expect(result.current.dialog?.message).toBe('Reset overrides for 1 column on Large?')
    })

    it('uses the singular noun for a one-column all-viewports scope', () => {
      const { wrapper } = setupWithOverrides({ xs: 1 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'All viewports'))
      })

      expect(result.current.dialog?.message).toBe('Reset all viewport overrides across 1 column?')
    })

    it('clears the dialog on cancel without calling the API', async () => {
      mockFetchSuccess({})
      const { wrapper } = setupWithOverrides({ lg: 2 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'Large'))
      })
      act(() => {
        result.current.onCancel()
      })

      expect(result.current.dialog).toBeNull()
      // Flush any queued mutation tasks, then assert the endpoint was never
      // hit — waitFor over a negative resolves on the first tick and
      // guarantees nothing.
      await flushObservers()
      expect(
        getFetchCalls().some(([url]) =>
          (url as string).includes('/api/resetGridSettingsOverrides'),
        ),
      ).toBe(false)
    })
  })

  describe('confirming', () => {
    it('sends the viewport for a viewport scope and closes the dialog', async () => {
      mockFetchSuccess({})
      const { wrapper } = setupWithOverrides({ xs: 1, lg: 3 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'Large'))
      })
      act(() => {
        result.current.onConfirm()
      })

      expect(result.current.dialog).toBeNull()
      const call = await findResetCall()
      expect(call[1]?.method).toBe('DELETE')
      expect(String(call[0])).toContain('pageId=1')
      expect(String(call[0])).toContain('zone=main')
      expect(String(call[0])).toContain('viewport=lg')
    })

    it('omits the viewport for the all-viewports scope', async () => {
      mockFetchSuccess({})
      const { wrapper } = setupWithOverrides({ xs: 1, lg: 3 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'All viewports'))
      })
      act(() => {
        result.current.onConfirm()
      })

      const call = await findResetCall()
      expect(String(call[0])).not.toContain('viewport=')
    })

    // The selected viewport must not leak into the scope.
    it('sends the requested viewport even while another tab is active', async () => {
      mockFetchSuccess({})
      const { wrapper } = setupWithOverrides({ xs: 1, lg: 3 }, 'xs')

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.requestReset(scopeNamed(result.current.options, 'Large'))
      })
      act(() => {
        result.current.onConfirm()
      })

      expect(String((await findResetCall())[0])).toContain('viewport=lg')
    })

    it('does nothing when confirmed with no scope pending', async () => {
      mockFetchSuccess({})
      const { wrapper } = setupWithOverrides({ lg: 2 })

      const { result } = renderHook(() => useResetOverridesAction(), { wrapper })
      act(() => {
        result.current.onConfirm()
      })

      // Flush any queued mutation tasks, then assert the endpoint was never
      // hit — waitFor over a negative resolves on the first tick and
      // guarantees nothing.
      await flushObservers()
      expect(
        getFetchCalls().some(([url]) =>
          (url as string).includes('/api/resetGridSettingsOverrides'),
        ),
      ).toBe(false)
    })
  })
})
