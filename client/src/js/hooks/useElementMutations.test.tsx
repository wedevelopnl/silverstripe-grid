import { QueryClient } from '@tanstack/react-query'
import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
import { allowConsole } from '@/testing/consoleGuard'
import {
  useCreateContentElement,
  useDuplicateToElement,
  usePublishElement,
  useReorderElement,
  useResetGridSettingsOverrides,
  useUnpublishElement,
  useUpdateGridSettings,
} from '@/hooks/useElementMutations'
import { useElementTree } from '@/hooks/useElementTree'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import {
  getFetchCalls,
  mockFetchError,
  mockFetchSequence,
  mockFetchSuccess,
} from '@/testing/mockFetch'
import { createProviderWrapper } from '@/testing/renderWithProviders'
import type { ContainerNode, TreeApiResponse } from '@/types/elements'

/**
 * Build a tree with explicit IDs and consistent parent chains for reorder tests.
 */
function createReorderTree(pageId = 1, zone = 'main') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })

  const column = createColumnNode({
    id: 300,
    parent: { type: 'row', id: 200 },
    childCount: 2,
  })
  // Re-parent the auto-generated child elements to point at column 300.
  for (const child of column.children ?? []) {
    ;(child as { parent: { type: 'column'; id: number }; parentKey: string }).parent = {
      type: 'column',
      id: 300,
    }
    ;(child as { parent: { type: 'column'; id: number }; parentKey: string }).parentKey =
      'column-300'
  }

  const row = createRowNode({
    id: 200,
    parent: { type: 'section', id: 100 },
    children: [column],
  })
  const section = createSectionNode({
    id: 100,
    parent: { type: 'page', id: pageId },
    children: [row],
  })

  const treeApiResponse = createTreeApiResponse({ pageId, sections: [section] })

  queryClient.setQueryData(queryKeys.elementTree.byPage(pageId, zone), treeApiResponse)

  const [elemA, elemB] = column.children ?? []

  return { queryClient, tree: treeApiResponse, treeApiResponse, column, elemA, elemB }
}

describe('useElementMutations', () => {
  beforeEach(() => {
    resetIdCounter()
  })

  describe('useCreateContentElement', () => {
    it('should call create endpoint', async () => {
      mockFetchSuccess({})
      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ className: 'Content', parent: { type: 'column', id: 10 } })
      })

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0)
      })

      const [url] = getFetchCalls()[0]
      expect(url).toContain('/api/create')
    })

    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Create failed' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ className: 'Content', parent: { type: 'column', id: 10 } })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })
    })
  })

  describe('useUpdateGridSettings', () => {
    it('should call updateGridSettings endpoint', async () => {
      mockFetchSuccess({})
      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useUpdateGridSettings(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({
          element: { type: 'column', id: 5 },
          viewport: 'md',
          width: 6,
          offset: 0,
          visible: true,
        })
      })

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0)
      })

      const [url] = getFetchCalls()[0]
      expect(url).toContain('/api/updateGridSettings')
    })

    it('should show toast on error', async () => {
      mockFetchError(422, { message: 'Invalid settings' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useUpdateGridSettings(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({
          element: { type: 'column', id: 5 },
          viewport: 'md',
          width: 6,
          offset: 0,
          visible: true,
        })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })
    })
  })

  describe('useResetGridSettingsOverrides', () => {
    it('should call resetGridSettingsOverrides endpoint', async () => {
      mockFetchSuccess({})
      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useResetGridSettingsOverrides(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ pageId: 1, zone: 'main' })
      })

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0)
      })

      const [url] = getFetchCalls()[0]
      expect(url).toContain('/api/resetGridSettingsOverrides')
    })

    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Reset failed' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useResetGridSettingsOverrides(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ pageId: 1, zone: 'main' })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })
    })
  })

  describe('usePublishElement', () => {
    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Publish failed' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => usePublishElement(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ type: 'section', id: 5 })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({
              type: 'error',
              text: expect.stringContaining('Publish failed'),
            }),
          }),
        )
      })
    })
  })

  describe('useUnpublishElement', () => {
    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Unpublish failed' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper()
      const { result } = renderHook(() => useUnpublishElement(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({ type: 'section', id: 5 })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({
              type: 'error',
              text: expect.stringContaining('Unpublish failed'),
            }),
          }),
        )
      })
    })
  })

  describe('useReorderElement', () => {
    it('should call reorder endpoint and apply optimistic update', async () => {
      mockFetchSuccess({})
      const { queryClient, tree, column, elemA, elemB } = createReorderTree()
      const queryKey = queryKeys.elementTree.byPage(1, 'main')
      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      await act(async () => {
        result.current.mutate({
          params: {
            element: { type: 'element', id: elemB.self.id },
            parent: { type: 'column', id: column.self.id },
            after: null,
          },
          tree,
        })
        // Flush onMutate microtask (cancelQueries)
        await Promise.resolve()
      })

      // Verify optimistic update: elemB moved before elemA (check before onSettled invalidates)
      const cached = queryClient.getQueryData<TreeApiResponse>(queryKey)
      const cachedSection = cached?.nodes[0] as ContainerNode
      const cachedRow = cachedSection.children?.[0] as ContainerNode
      const cachedColumn = cachedRow.children?.[0] as ContainerNode
      expect(cachedColumn.children?.map((c) => c.self.id)).toEqual([elemB.self.id, elemA.self.id])

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0)
      })
      const [url] = getFetchCalls()[0]
      expect(url).toContain('/api/reorder')
    })

    it('should show toast and restore snapshot on error', async () => {
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree()
      mockFetchSequence([
        { status: 500, body: { message: 'Reorder failed' } },
        // The onSettled invalidation triggers a refetch — provide the original response
        { status: 200, body: treeApiResponse },
      ])
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      act(() => {
        result.current.mutate({
          params: {
            element: { type: 'element', id: elemB.self.id },
            parent: { type: 'column', id: column.self.id },
            after: null,
          },
          tree,
        })
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })
    })

    it('does not refetch after an optimistic rollback', async () => {
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree()
      // Prevent the mounted useElementTree observer from doing its own
      // on-mount background refetch — we only care about the invalidation.
      queryClient.setDefaultOptions({ queries: { retry: false, gcTime: 0, staleTime: Infinity } })
      // Queue: the failed reorder POST only. If onSettled invalidates on
      // error, an active tree query observer will trigger a refetch — caught
      // by the "exactly one call" assertion below.
      mockFetchSequence([
        { status: 422, body: { message: 'hierarchy' } },
        // Safety net: if the refetch does happen, give it a valid response so
        // the test fails cleanly on the call-count assertion instead of crashing.
        { status: 200, body: treeApiResponse },
      ])
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper({ queryClient })
      // Mount a reader for the tree query so it becomes an *active* query —
      // TanStack Query only refetches observed queries on invalidation.
      renderHook(() => useElementTree(1, 'main'), { wrapper })

      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current
          .mutateAsync({
            params: {
              element: { type: 'element', id: elemB.self.id },
              parent: { type: 'column', id: column.self.id },
              after: null,
            },
            tree,
          })
          .catch(() => undefined)
      })

      // Wait for the error toast so the mutation has fully settled.
      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })

      // Flush any queued microtasks that an invalidate-triggered refetch
      // would use to schedule its fetch.
      await act(async () => {
        await Promise.resolve()
      })

      // Exactly one call — the failed reorder POST. No refetch after rollback.
      const reorderCalls = getFetchCalls().filter(([url]) => String(url).includes('/api/reorder'))
      const treeCalls = getFetchCalls().filter(([url]) => String(url).includes('/api/readTree'))
      expect(reorderCalls).toHaveLength(1)
      expect(treeCalls).toHaveLength(0)
    })

    it('should call clearPendingTree on error as safety net', async () => {
      // The failed reorder surfaces an error toast (console.warn) by design.
      allowConsole('[GridEditor] error:')
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree()
      mockFetchSequence([
        { status: 500, body: { message: 'fail' } },
        { status: 200, body: treeApiResponse },
      ])

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      const clearPendingTree = vi.fn()

      act(() => {
        result.current.mutate({
          params: {
            element: { type: 'element', id: elemB.self.id },
            parent: { type: 'column', id: column.self.id },
            after: null,
          },
          tree,
          clearPendingTree,
        })
      })

      await waitFor(() => {
        expect(clearPendingTree).toHaveBeenCalled()
      })
    })

    it('restores the snapshot tree into the cache on error', async () => {
      // Pins the `if (snapshot !== undefined) setQueryData(snapshot)` rollback
      // at useElementMutations.ts:169. onMutate writes an optimistic order
      // [elemB, elemA]; the reorder POST fails; onError must restore the cache
      // to the captured snapshot order [elemA, elemB]. No tree observer is
      // mounted, so the success-only invalidation cannot refetch and overwrite.
      // gcTime: Infinity from construction keeps the rolled-back cache entry
      // alive for inspection — with gcTime 0 and no mounted observer the entry
      // is garbage-collected the moment the mutation settles.
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false, gcTime: Infinity } },
      })
      const queryKey = queryKeys.elementTree.byPage(1, 'main')

      const column = createColumnNode({ id: 300, parent: { type: 'row', id: 200 }, childCount: 2 })
      for (const child of column.children ?? []) {
        ;(child as { parent: { type: 'column'; id: number }; parentKey: string }).parent = {
          type: 'column',
          id: 300,
        }
        ;(child as { parent: { type: 'column'; id: number }; parentKey: string }).parentKey =
          'column-300'
      }
      const row = createRowNode({
        id: 200,
        parent: { type: 'section', id: 100 },
        children: [column],
      })
      const section = createSectionNode({
        id: 100,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })
      queryClient.setQueryData(queryKey, tree)
      const [elemA, elemB] = column.children ?? []

      mockFetchError(500, { message: 'Reorder failed' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current
          .mutateAsync({
            params: {
              element: { type: 'element', id: elemB.self.id },
              parent: { type: 'column', id: column.self.id },
              after: null,
            },
            tree,
          })
          .catch(() => undefined)
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })

      // Cache must reflect the restored snapshot order, not the optimistic swap.
      const cached = queryClient.getQueryData<TreeApiResponse>(queryKey)
      const cachedSection = cached?.nodes[0] as ContainerNode
      const cachedRow = cachedSection.children?.[0] as ContainerNode
      const cachedColumn = cachedRow.children?.[0] as ContainerNode
      expect(cachedColumn.children?.map((c) => c.self.id)).toEqual([elemA.self.id, elemB.self.id])
    })

    it('skips setQueryData rollback when no snapshot was captured', async () => {
      // Fresh QueryClient — no pre-seeded tree, so onMutate's
      // getQueryData returns undefined and onError must NOT attempt to
      // restore a snapshot. Pins the `if (snapshot !== undefined)` guard
      // at useElementMutations.ts:152.
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false, gcTime: 0 } },
      })
      const setQueryDataSpy = vi.spyOn(queryClient, 'setQueryData')

      mockFetchError(500, { message: 'fail' })
      const dispatch = vi.fn()
      window.ss!.store = { dispatch }

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      // Build a minimal tree for the onMutate applyReorder call. The tree
      // is NOT seeded into the cache — that's the scenario under test.
      const column = createColumnNode({
        id: 300,
        parent: { type: 'row', id: 200 },
        childCount: 1,
      })
      const row = createRowNode({
        id: 200,
        parent: { type: 'section', id: 100 },
        children: [column],
      })
      const section = createSectionNode({
        id: 100,
        parent: { type: 'page', id: 1 },
        children: [row],
      })
      const tree = createTreeApiResponse({ pageId: 1, sections: [section] })
      const elem = column.children?.[0]

      await act(async () => {
        await result.current
          .mutateAsync({
            params: {
              element: { type: 'element', id: elem!.self.id },
              parent: { type: 'column', id: 300 },
              after: null,
            },
            tree,
          })
          .catch(() => undefined)
      })

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        )
      })

      // onMutate ran setQueryData once (optimistic write on undefined
      // snapshot). onError must NOT have called it again with the
      // (undefined) snapshot.
      const restoreCalls = setQueryDataSpy.mock.calls.filter(([, value]) => value === undefined)
      expect(restoreCalls).toHaveLength(0)
    })

    it('onMutate cancels the specific tree queryKey, not all queries', async () => {
      // Pins the ObjectLiteral mutation on cancelQueries({ queryKey })
      // at useElementMutations.ts:129 — mutated to {} it would cancel
      // every query, defeating the per-page scope.
      const { queryClient, tree, column, elemB } = createReorderTree()
      const cancelQueriesSpy = vi.spyOn(queryClient, 'cancelQueries')
      mockFetchSuccess({})

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      await act(async () => {
        result.current.mutate({
          params: {
            element: { type: 'element', id: elemB.self.id },
            parent: { type: 'column', id: column.self.id },
            after: null,
          },
          tree,
        })
        await Promise.resolve()
      })

      expect(cancelQueriesSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(1, 'main'),
      })
    })

    it('onSuccess invalidates the specific tree queryKey', async () => {
      // Pins the ObjectLiteral mutation on invalidateQueries({ queryKey })
      // at useElementMutations.ts:161.
      const { queryClient, tree, column, elemB } = createReorderTree()
      const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
      mockFetchSuccess({})

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current.mutateAsync({
          params: {
            element: { type: 'element', id: elemB.self.id },
            parent: { type: 'column', id: column.self.id },
            after: null,
          },
          tree,
        })
      })

      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(1, 'main'),
      })
    })
  })

  describe('useDuplicateToElement', () => {
    it('invalidates both the source and destination tree on a cross-target duplicate', async () => {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false, gcTime: 0 } },
      })
      const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
      mockFetchSuccess({})

      const { wrapper } = createProviderWrapper({ queryClient })
      // Source: page 1 / 'main'. Destination: page 9 / 'sidebar'.
      const { result } = renderHook(() => useDuplicateToElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current.mutateAsync({
          element: { type: 'section', id: 5 },
          targetPageId: 9,
          targetZone: 'sidebar',
          targetParent: { type: 'page', id: 9 },
        })
      })

      // Source tree (from the hook args).
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(1, 'main'),
      })
      // Destination tree (from the mutation variables) — without this the
      // duplicate would not appear on the target page without a manual refetch.
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(9, 'sidebar'),
      })
    })
  })

  // ─── Shared onSuccess defaults (useStandardMutationOptions) ──────
  //
  // Pins the onSuccess body at useElementMutations.ts:41 — without
  // invalidateQueries the cache stays stale after a write, breaking the
  // CMS read-after-write contract. All mutations spreading this factory
  // share the same behaviour; one targeted test proves the factory works.

  describe('useStandardMutationOptions onSuccess', () => {
    it('invalidates the specific page tree queryKey after a successful mutation', async () => {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false, gcTime: 0 } },
      })
      const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
      mockFetchSuccess({})

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current.mutateAsync({
          className: 'Content',
          parent: { type: 'column', id: 10 },
        })
      })

      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(1, 'main'),
      })
    })

    it('invalidates the acceptableContainers query after a successful create', async () => {
      const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false, gcTime: 0 } },
      })
      const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries')
      mockFetchSuccess({})

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper })

      await act(async () => {
        await result.current.mutateAsync({
          className: 'Content',
          parent: { type: 'column', id: 10 },
        })
      })

      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.acceptableContainers.all(),
      })
    })
  })
})
