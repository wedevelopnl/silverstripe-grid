// biome-ignore-all lint/nursery/useAwaitThenable: Biome 2.5.6+ resolves React's inapplicable `act(() => VoidOrUndefinedOnly): void` overload for async callbacks and reports the awaits below as non-Promise. TypeScript picks the `act<T>(() => T | Promise<T>): Promise<T>` overload, so `tsc` is clean. Remove once Biome fixes overload applicability in this rule.

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
import { buildTree, resetIdCounter } from '@/testing/factories'
import {
  getFetchCalls,
  mockFetchError,
  mockFetchSequence,
  mockFetchSuccess,
} from '@/testing/mockFetch'
import { createProviderWrapper, createTestQueryClient } from '@/testing/renderWithProviders'
import type { ContainerNode, TreeApiResponse } from '@/types/elements'

/**
 * Build a tree with explicit IDs and consistent parent chains for reorder
 * tests: section 100 → row 200 → column 300 with two auto-id elements.
 * The tree is seeded into the returned QueryClient's cache unless
 * `seedCache: false`; `gcTime` overrides the test default of 0.
 */
function createReorderTree(
  pageId = 1,
  zone = 'main',
  options: { gcTime?: number; seedCache?: boolean } = {},
) {
  const queryClient =
    options.gcTime === undefined
      ? createTestQueryClient()
      : new QueryClient({
          defaultOptions: { queries: { retry: false, gcTime: options.gcTime } },
        })

  const { tree, columns } = buildTree({
    pageId,
    sectionId: 100,
    rows: [{ id: 200, columns: [{ id: 300, childCount: 2 }] }],
  })
  const column = columns[0]

  if (options.seedCache !== false) {
    queryClient.setQueryData(queryKeys.elementTree.byPage(pageId, zone), tree)
  }

  const [elemA, elemB] = column.children ?? []

  return { queryClient, tree, treeApiResponse: tree, column, elemA, elemB }
}

/** Install a fresh CMS store dispatch stub and return it for toast assertions. */
function stubToastDispatch() {
  const dispatch = vi.fn()
  window.ss!.store = { dispatch }
  return dispatch
}

async function awaitErrorToast(dispatch: ReturnType<typeof vi.fn>) {
  await waitFor(() => {
    expect(dispatch).toHaveBeenCalledWith(
      expect.objectContaining({
        type: 'DISPLAY_TOAST',
        payload: expect.objectContaining({ type: 'error' }),
      }),
    )
  })
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
  })

  // The error toast fires from the single shared useStandardMutationOptions
  // onError (useElementMutations.ts:51-52). One parametrized test per consuming
  // hook keeps each hook's error path exercised without five copies of the
  // arrange/assert shape.
  describe('useStandardMutationOptions onError', () => {
    it.each([
      {
        name: 'useCreateContentElement',
        hook: () => useCreateContentElement(1, 'main'),
        variables: { className: 'Content', parent: { type: 'column', id: 10 } },
        status: 500,
        message: 'Create failed',
      },
      {
        name: 'useUpdateGridSettings',
        hook: () => useUpdateGridSettings(1, 'main'),
        variables: {
          element: { type: 'column', id: 5 },
          viewport: 'md',
          width: 6,
          offset: 0,
          visible: true,
        },
        status: 422,
        message: 'Invalid settings',
      },
      {
        name: 'useResetGridSettingsOverrides',
        hook: () => useResetGridSettingsOverrides(1, 'main'),
        variables: { pageId: 1, zone: 'main' },
        status: 500,
        message: 'Reset failed',
      },
      {
        name: 'usePublishElement',
        hook: () => usePublishElement(1, 'main'),
        variables: { type: 'section', id: 5 },
        status: 500,
        message: 'Publish failed',
      },
      {
        name: 'useUnpublishElement',
        hook: () => useUnpublishElement(1, 'main'),
        variables: { type: 'section', id: 5 },
        status: 500,
        message: 'Unpublish failed',
      },
    ])(
      'shows an error toast with the API message when $name fails',
      async ({ hook, variables, status, message }) => {
        mockFetchError(status, { message })
        const dispatch = stubToastDispatch()

        const { wrapper } = createProviderWrapper()
        // The rows return differently-parameterised mutations; the test only
        // needs the mutate seam, so widen to that.
        const { result } = renderHook(hook as () => { mutate: (variables: never) => void }, {
          wrapper,
        })

        act(() => {
          result.current.mutate(variables as never)
        })

        await waitFor(() => {
          expect(dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
              type: 'DISPLAY_TOAST',
              payload: expect.objectContaining({
                type: 'error',
                text: expect.stringContaining(message),
              }),
            }),
          )
        })
      },
    )
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
      const dispatch = stubToastDispatch()

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

      await awaitErrorToast(dispatch)
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
      const dispatch = stubToastDispatch()

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
      await awaitErrorToast(dispatch)

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
      // gcTime: Infinity keeps the rolled-back cache entry alive for
      // inspection — with gcTime 0 and no mounted observer the entry is
      // garbage-collected the moment the mutation settles.
      const { queryClient, tree, column, elemA, elemB } = createReorderTree(1, 'main', {
        gcTime: Infinity,
      })
      const queryKey = queryKeys.elementTree.byPage(1, 'main')

      mockFetchError(500, { message: 'Reorder failed' })
      const dispatch = stubToastDispatch()

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

      await awaitErrorToast(dispatch)

      // Cache must reflect the restored snapshot order, not the optimistic swap.
      const cached = queryClient.getQueryData<TreeApiResponse>(queryKey)
      const cachedSection = cached?.nodes[0] as ContainerNode
      const cachedRow = cachedSection.children?.[0] as ContainerNode
      const cachedColumn = cachedRow.children?.[0] as ContainerNode
      expect(cachedColumn.children?.map((c) => c.self.id)).toEqual([elemA.self.id, elemB.self.id])
    })

    it('skips setQueryData rollback when no snapshot was captured', async () => {
      // No pre-seeded tree (seedCache: false), so onMutate's getQueryData
      // returns undefined and onError must NOT attempt to restore a snapshot.
      // Pins the `if (snapshot !== undefined)` guard at useElementMutations.ts:152.
      const { queryClient, tree, column, elemB } = createReorderTree(1, 'main', {
        seedCache: false,
      })
      const setQueryDataSpy = vi.spyOn(queryClient, 'setQueryData')

      mockFetchError(500, { message: 'fail' })
      const dispatch = stubToastDispatch()

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

      await awaitErrorToast(dispatch)

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

    // In the library editor `pageId` is a BLOCK id and the rendered tree comes
    // from `sharedBlocks.tree`. Writing the optimistic update to `elementTree`
    // there put it in an entry nothing displays: the drag snapped back and the
    // stale order survived until a reload.
    it('optimistically updates the block tree when rooted at a shared block', async () => {
      mockFetchSuccess({})
      const blockId = 1
      // No page-tree entry at all: the library editor has none, so seeding one
      // would assert "never writes elementTree" against a cache state that
      // cannot occur, leaving the claim resting on spy-install ordering.
      const { queryClient, tree, column, elemA, elemB } = createReorderTree(1, '', {
        seedCache: false,
      })
      const blockKey = queryKeys.sharedBlocks.tree(blockId)
      queryClient.setQueryData(blockKey, tree)

      // Asserted on the write rather than on the cache afterwards: the test
      // client uses gcTime 0, so an entry with no observer is collected before
      // the assertion could read it back.
      const setQueryDataSpy = vi.spyOn(queryClient, 'setQueryData')

      const { wrapper } = createProviderWrapper({ queryClient })
      const { result } = renderHook(() => useReorderElement(blockId, '', 'sharedBlock'), {
        wrapper,
      })

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

      const writtenKeys = setQueryDataSpy.mock.calls.map(([key]) => key)
      expect(writtenKeys).toContainEqual(blockKey)
      expect(writtenKeys.every((key) => (key as unknown[])[0] !== 'elementTree')).toBe(true)

      const optimistic = setQueryDataSpy.mock.calls.find(
        ([key]) => JSON.stringify(key) === JSON.stringify(blockKey),
      )?.[1] as TreeApiResponse
      const optimisticSection = optimistic.nodes[0] as ContainerNode
      const optimisticRow = optimisticSection.children?.[0] as ContainerNode
      const optimisticColumn = optimisticRow.children?.[0] as ContainerNode
      expect(optimisticColumn.children?.map((c) => c.self.id)).toEqual([
        elemB.self.id,
        elemA.self.id,
      ])
    })
  })

  describe('useDuplicateToElement', () => {
    it('invalidates both the source and destination tree on a cross-target duplicate', async () => {
      const queryClient = createTestQueryClient()
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

  // Pins the onSuccess body at useElementMutations.ts:41 — without
  // invalidateQueries the cache stays stale after a write, breaking the
  // CMS read-after-write contract. All mutations spreading this factory
  // share the same behaviour; one targeted test proves the factory works.

  describe('useStandardMutationOptions onSuccess', () => {
    it('invalidates the specific page tree queryKey after a successful mutation', async () => {
      const queryClient = createTestQueryClient()
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
      const queryClient = createTestQueryClient()
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
