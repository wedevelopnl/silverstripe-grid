import { QueryClient } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
import { useEditorTree, useViewportOverrideCounts } from '@/hooks/useEditorTree'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper, createTestQueryClient } from '@/testing/renderWithProviders'
import type { EditorRoot } from '@/types/editorRoot'
import type { ColumnNode, TreeApiResponse, ViewportSettings } from '@/types/elements'

const PAGE: EditorRoot = { kind: 'page', pageId: 1, zone: 'main' }

const override: ViewportSettings = { width: 6, offset: 0, visible: true }
const defaults: ViewportSettings = { width: 12, offset: 0, visible: true }

function treeWithOverrides(spec: Record<string, number>): TreeApiResponse {
  const columns: ColumnNode[] = []
  for (const [viewport, count] of Object.entries(spec)) {
    for (let i = 0; i < count; i++) {
      columns.push(
        createColumnNode({
          gridSettings: { default: defaults, overrides: { [viewport]: override } },
          children: [],
        }),
      )
    }
  }
  const row = createRowNode({ children: columns })
  const section = createSectionNode({ parent: { type: 'page', id: 1 }, children: [row] })
  return createTreeApiResponse({ pageId: 1, sections: [section] })
}

describe('useEditorTree', () => {
  beforeEach(() => {
    resetIdCounter()
  })

  it('should fetch and select tree when pageId is provided', async () => {
    const apiResponse = createTreeApiResponse()
    mockFetchSuccess(apiResponse)
    const { wrapper } = createProviderWrapper()

    const { result } = renderHook(() => useEditorTree(PAGE), { wrapper })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    expect(result.current.data?.nodes).toHaveLength(apiResponse.nodes.length)
    expect(result.current.data?.rootParent).toEqual(apiResponse.rootParent)
    const [url] = getFetchCalls()[0]
    expect(url).toContain('/api/readTree/1/main')
  })

  it('should not refetch a version-specific tree when it remounts', async () => {
    // Archived versions are immutable, so staleTime: Infinity must let a remount
    // reuse the cache. Needs a cache that survives unmount (the shared test client
    // uses gcTime: 0) and a stale-on-arrival default to prove staleTime is what
    // suppresses the second fetch.
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 5 * 60 * 1000, staleTime: 0 } },
    })
    mockFetchSuccess(createTreeApiResponse())
    const { wrapper } = createProviderWrapper({ queryClient })

    const first = renderHook(() => useEditorTree({ ...PAGE, version: 7 }), { wrapper })
    await waitFor(() => {
      expect(first.result.current.isSuccess).toBe(true)
    })
    first.unmount()

    const second = renderHook(() => useEditorTree({ ...PAGE, version: 7 }), { wrapper })
    await waitFor(() => {
      expect(second.result.current.isSuccess).toBe(true)
    })

    expect(getFetchCalls()).toHaveLength(1)
  })

  it('should not refetch a fresh draft tree when it remounts within staleTime', async () => {
    // The draft tree carries a 30s staleTime: mutations invalidate the query
    // explicitly, so a remount (or window refocus) shortly after a fetch must
    // reuse the cache instead of rebuilding the tree server-side. The default
    // here is stale-on-arrival to prove the option — not the default — is what
    // suppresses the second fetch.
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 5 * 60 * 1000, staleTime: 0 } },
    })
    mockFetchSuccess(createTreeApiResponse())
    const { wrapper } = createProviderWrapper({ queryClient })

    const first = renderHook(() => useEditorTree(PAGE), { wrapper })
    await waitFor(() => {
      expect(first.result.current.isSuccess).toBe(true)
    })
    first.unmount()

    const second = renderHook(() => useEditorTree(PAGE), { wrapper })
    await waitFor(() => {
      expect(second.result.current.isSuccess).toBe(true)
    })

    expect(getFetchCalls()).toHaveLength(1)
  })

  it('should refetch a draft tree after invalidation despite staleTime', async () => {
    // The staleTime above is only safe because invalidation overrides it —
    // every mutation invalidates the tree query, and that MUST refetch even
    // inside the freshness window. This pins that override.
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 5 * 60 * 1000, staleTime: 0 } },
    })
    mockFetchSuccess(createTreeApiResponse())
    const { wrapper } = createProviderWrapper({ queryClient })

    const { result } = renderHook(() => useEditorTree(PAGE), { wrapper })
    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    await queryClient.invalidateQueries({ queryKey: queryKeys.elementTree.byPage(1, 'main') })

    await waitFor(() => {
      expect(getFetchCalls()).toHaveLength(2)
    })
  })

  it('reads the block-rooted route when the editor is rooted at a block', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })
    const { wrapper } = createProviderWrapper()

    const { result } = renderHook(() => useEditorTree({ kind: 'sharedBlock', blockId: 9 }), {
      wrapper,
    })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    const [url] = getFetchCalls()[0]
    expect(url).toContain('/grid-shared-blocks/api/readTree/9')
  })

  it('should not fetch when there is no root', () => {
    mockFetchSuccess({})
    const { wrapper } = createProviderWrapper()
    const { result } = renderHook(() => useEditorTree(null), { wrapper })

    expect(result.current.isFetching).toBe(false)
    expect(result.current.data).toBeUndefined()
    expect(getFetchCalls()).toHaveLength(0)
  })

  it('should append /version/N path segment when version is provided', async () => {
    const apiResponse = createTreeApiResponse()
    mockFetchSuccess(apiResponse)
    const { wrapper } = createProviderWrapper()

    const { result } = renderHook(() => useEditorTree({ ...PAGE, version: 5 }), { wrapper })

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    const [url] = getFetchCalls()[0]
    expect(url).toContain('/api/readTree/1/main/version/5')
  })
})

describe('useViewportOverrideCounts', () => {
  beforeEach(() => {
    resetIdCounter()
  })

  it('should derive counts from cached tree nodes', async () => {
    // 3 md-override columns, 1 lg-override column → total=4, md=3, lg=1
    const apiResponse = treeWithOverrides({ md: 3, lg: 1 })

    const queryClient = createTestQueryClient()
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), apiResponse)

    const { wrapper } = createProviderWrapper({ queryClient })
    const { result } = renderHook(() => useViewportOverrideCounts(PAGE), { wrapper })

    await waitFor(() => {
      expect(result.current).toEqual({ total: 4, byViewport: { md: 3, lg: 1 } })
    })
  })

  it('should return zero counts when no data is cached', () => {
    mockFetchSuccess({})
    const { wrapper } = createProviderWrapper()
    const { result } = renderHook(() => useViewportOverrideCounts(null), { wrapper })

    expect(result.current).toEqual({ total: 0, byViewport: {} })
  })

  it('should use version-keyed cache entry when version is provided', async () => {
    const apiResponse = treeWithOverrides({ md: 2 })

    const queryClient = createTestQueryClient()
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main', 5), apiResponse)

    const { wrapper } = createProviderWrapper({ queryClient })
    const { result } = renderHook(() => useViewportOverrideCounts({ ...PAGE, version: 5 }), {
      wrapper,
    })

    await waitFor(() => {
      expect(result.current).toEqual({ total: 2, byViewport: { md: 2 } })
    })
  })
})
