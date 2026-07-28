import { QueryClient } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
import { useElementTree, useViewportOverrideCounts } from '@/hooks/useElementTree'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createProviderWrapper } from '@/testing/renderWithProviders'
import type { ColumnNode, TreeApiResponse, ViewportSettings } from '@/types/elements'

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

describe('useElementTree', () => {
  beforeEach(() => {
    resetIdCounter()
  })

  it('should fetch and select tree when pageId is provided', async () => {
    const apiResponse = createTreeApiResponse()
    mockFetchSuccess(apiResponse)
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main' })

    const { result } = renderHook(() => useElementTree(1, 'main'), { wrapper })

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
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main', queryClient })

    const first = renderHook(() => useElementTree(1, 'main', 7), { wrapper })
    await waitFor(() => {
      expect(first.result.current.isSuccess).toBe(true)
    })
    first.unmount()

    const second = renderHook(() => useElementTree(1, 'main', 7), { wrapper })
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
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main', queryClient })

    const first = renderHook(() => useElementTree(1, 'main'), { wrapper })
    await waitFor(() => {
      expect(first.result.current.isSuccess).toBe(true)
    })
    first.unmount()

    const second = renderHook(() => useElementTree(1, 'main'), { wrapper })
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
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main', queryClient })

    const { result } = renderHook(() => useElementTree(1, 'main'), { wrapper })
    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true)
    })

    await queryClient.invalidateQueries({ queryKey: queryKeys.elementTree.byPage(1, 'main') })

    await waitFor(() => {
      expect(getFetchCalls()).toHaveLength(2)
    })
  })

  it('should not fetch when pageId is null', () => {
    mockFetchSuccess({})
    const { wrapper } = createProviderWrapper()
    const { result } = renderHook(() => useElementTree(null, 'main'), { wrapper })

    expect(result.current.isFetching).toBe(false)
    expect(result.current.data).toBeUndefined()
    expect(getFetchCalls()).toHaveLength(0)
  })

  it('should append /version/N path segment when version is provided', async () => {
    const apiResponse = createTreeApiResponse()
    mockFetchSuccess(apiResponse)
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main' })

    const { result } = renderHook(() => useElementTree(1, 'main', 5), { wrapper })

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
    // 3 md-override columns, 1 lg-override column → _total=4, md=3, lg=1
    const apiResponse = treeWithOverrides({ md: 3, lg: 1 })

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), apiResponse)

    const { wrapper } = createProviderWrapper({ queryClient, pageId: 1, zone: 'main' })
    const { result } = renderHook(() => useViewportOverrideCounts(1, 'main'), { wrapper })

    await waitFor(() => {
      expect(result.current).toEqual({ _total: 4, md: 3, lg: 1 })
    })
  })

  it('should return empty object when no data is cached', () => {
    mockFetchSuccess({})
    const { wrapper } = createProviderWrapper()
    const { result } = renderHook(() => useViewportOverrideCounts(null, 'main'), { wrapper })

    expect(result.current).toEqual({})
  })

  it('should use version-keyed cache entry when version is provided', async () => {
    const apiResponse = treeWithOverrides({ md: 2 })

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main', 5), apiResponse)

    const { wrapper } = createProviderWrapper({ queryClient, pageId: 1, zone: 'main' })
    const { result } = renderHook(() => useViewportOverrideCounts(1, 'main', 5), { wrapper })

    await waitFor(() => {
      expect(result.current).toEqual({ _total: 2, md: 2 })
    })
  })
})
