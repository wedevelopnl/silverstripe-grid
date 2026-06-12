import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it } from 'vitest'
import { mockFetchSuccess } from '@/testing/mockFetch'
import type { AcceptableContainer, PageEntry } from '@/types/duplicateTo'
import { useAcceptableContainers, usePages, useZones } from './useDuplicateToQueries'

function createWrapperWithClient() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })

  function Wrapper({ children }: { children: ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }

  return { Wrapper, queryClient }
}

function createWrapper() {
  return createWrapperWithClient().Wrapper
}

describe('usePages', () => {
  it('should not fetch when enabled is false', () => {
    const wrapper = createWrapper()

    const { result } = renderHook(() => usePages('test', false), { wrapper })

    expect(result.current.isFetching).toBe(false)
    expect(result.current.data).toBeUndefined()
  })

  it('should fetch pages when enabled', async () => {
    const pages: PageEntry[] = [{ id: 1, title: 'Home', parentId: 0, hasGridZones: true }]
    mockFetchSuccess(pages)
    const wrapper = createWrapper()

    const { result } = renderHook(() => usePages('home'), { wrapper })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data).toEqual(pages)
  })
})

describe('useZones', () => {
  it('should not fetch when pageId is null', () => {
    const wrapper = createWrapper()

    const { result } = renderHook(() => useZones(null), { wrapper })

    expect(result.current.isFetching).toBe(false)
    expect(result.current.data).toBeUndefined()
  })

  it('should fetch zones when pageId is provided', async () => {
    const zones = ['main', 'sidebar']
    mockFetchSuccess(zones)
    const wrapper = createWrapper()

    const { result } = renderHook(() => useZones(1), { wrapper })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data).toEqual(zones)
  })
})

describe('useAcceptableContainers', () => {
  it('should not fetch when pageId is null', () => {
    const wrapper = createWrapper()

    const { result } = renderHook(() => useAcceptableContainers(null, 'main', 'Section'), {
      wrapper,
    })

    expect(result.current.isFetching).toBe(false)
  })

  it('should not fetch when zone is null', () => {
    const wrapper = createWrapper()

    const { result } = renderHook(() => useAcceptableContainers(1, null, 'Section'), { wrapper })

    expect(result.current.isFetching).toBe(false)
  })

  it('should not fetch when elementType is null', () => {
    const wrapper = createWrapper()

    const { result } = renderHook(() => useAcceptableContainers(1, 'main', null), { wrapper })

    expect(result.current.isFetching).toBe(false)
  })

  it('should fetch when all dependencies are provided', async () => {
    const containers: AcceptableContainer[] = [{ id: 10, title: 'Column 1', type: 'column' }]
    mockFetchSuccess(containers)
    const wrapper = createWrapper()

    const { result } = renderHook(() => useAcceptableContainers(1, 'main', 'section'), { wrapper })

    await waitFor(() => expect(result.current.isSuccess).toBe(true))
    expect(result.current.data).toEqual(containers)
  })
})

describe('disabled query key sentinels', () => {
  // Disabled queries must use a distinct sentinel so they don't share cache
  // entries with a real pageId of 0, which would leak stale data across pages.
  it('useZones uses a disabled sentinel key when pageId is null', () => {
    const { Wrapper, queryClient } = createWrapperWithClient()

    renderHook(() => useZones(null), { wrapper: Wrapper })

    expect(queryClient.getQueryCache().find({ queryKey: ['zones', 0] })).toBeUndefined()
    expect(queryClient.getQueryCache().find({ queryKey: ['zones', 'disabled'] })).toBeDefined()
  })

  it('useAcceptableContainers uses a disabled sentinel key when any input is null', () => {
    const { Wrapper, queryClient } = createWrapperWithClient()

    renderHook(() => useAcceptableContainers(null, null, null), { wrapper: Wrapper })

    expect(
      queryClient.getQueryCache().find({ queryKey: ['acceptableContainers', 0, '', ''] }),
    ).toBeUndefined()
    expect(
      queryClient.getQueryCache().find({ queryKey: ['acceptableContainers', 'disabled'] }),
    ).toBeDefined()
  })
})
