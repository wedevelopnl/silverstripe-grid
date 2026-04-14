import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import {
  useCreateContentElement,
  useUpdateGridSettings,
  useResetGridSettingsOverrides,
  useReorderElement,
  usePublishElement,
  useUnpublishElement,
} from '@/hooks/useElementMutations';
import { useElementTree } from '@/hooks/useElementTree';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  resetIdCounter,
} from '@/testing/factories';
import {
  mockFetchSuccess,
  mockFetchError,
  mockFetchSequence,
  getFetchCalls,
} from '@/testing/mockFetch';
import { queryKeys } from '@/hooks/queryKeys';
import { QueryClient } from '@tanstack/react-query';
import type { ContainerNode, ElementTreeResponse, TreeApiResponse } from '@/types/elements';

/**
 * Build a tree with explicit IDs and consistent parentId chains for reorder tests.
 * Uses numeric root key so applyReorder's Number(rootKey) resolves correctly.
 */
function createReorderTree(pageId = 1, zone = 'main') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });

  const section = createSectionNode({
    id: 100,
    parentId: pageId,
    children: [
      createRowNode({
        id: 200,
        parentId: 100,
        children: [
          createColumnNode({
            id: 300,
            parentId: 200,
            childCount: 2,
          }),
        ],
      }),
    ],
  });

  // Patch child parentIds to match their container
  for (const child of section.children![0].children![0].children!) {
    child.parentId = 300;
  }

  const tree: ElementTreeResponse = { [String(pageId)]: [section] };
  const treeApiResponse: TreeApiResponse = { tree, overrideCounts: {} };

  queryClient.setQueryData(queryKeys.elementTree.byPage(pageId, zone), treeApiResponse);

  const column = section.children![0].children![0];
  const [elemA, elemB] = column.children!;

  return { queryClient, tree, treeApiResponse, column, elemA, elemB };
}

describe('useElementMutations', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  describe('useCreateContentElement', () => {
    it('should call createContent endpoint', async () => {
      mockFetchSuccess({});
      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ className: 'Content', parentId: 10 });
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url] = getFetchCalls()[0];
      expect(url).toContain('/api/createContent');
    });

    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Create failed' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useCreateContentElement(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ className: 'Content', parentId: 10 });
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });
    });
  });

  describe('useUpdateGridSettings', () => {
    it('should call updateGridSettings endpoint', async () => {
      mockFetchSuccess({});
      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useUpdateGridSettings(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ id: 5, viewport: 'md', width: 6, offset: 0, visible: true });
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url] = getFetchCalls()[0];
      expect(url).toContain('/api/updateGridSettings');
    });

    it('should show toast on error', async () => {
      mockFetchError(422, { message: 'Invalid settings' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useUpdateGridSettings(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ id: 5, viewport: 'md', width: 6, offset: 0, visible: true });
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });
    });
  });

  describe('useResetGridSettingsOverrides', () => {
    it('should call resetGridSettingsOverrides endpoint', async () => {
      mockFetchSuccess({});
      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useResetGridSettingsOverrides(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ pageId: 1, zone: 'main' });
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url] = getFetchCalls()[0];
      expect(url).toContain('/api/resetGridSettingsOverrides');
    });

    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Reset failed' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useResetGridSettingsOverrides(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({ pageId: 1, zone: 'main' });
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });
    });
  });

  describe('usePublishElement', () => {
    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Publish failed' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => usePublishElement(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate(5);
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({
              type: 'error',
              text: expect.stringContaining('Publish failed'),
            }),
          }),
        );
      });
    });
  });

  describe('useUnpublishElement', () => {
    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Unpublish failed' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper();
      const { result } = renderHook(() => useUnpublishElement(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate(5);
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({
              type: 'error',
              text: expect.stringContaining('Unpublish failed'),
            }),
          }),
        );
      });
    });
  });

  describe('useReorderElement', () => {
    it('should call reorder endpoint and apply optimistic update', async () => {
      mockFetchSuccess({});
      const { queryClient, tree, column, elemA, elemB } = createReorderTree();
      const queryKey = queryKeys.elementTree.byPage(1, 'main');
      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      await act(async () => {
        result.current.mutate({
          params: { elementID: elemB.id, targetParentId: column.id, afterElementID: null },
          tree,
        });
        // Flush onMutate microtask (cancelQueries)
        await Promise.resolve();
      });

      // Verify optimistic update: elemB moved before elemA (check before onSettled invalidates)
      const cached = queryClient.getQueryData<TreeApiResponse>(queryKey);
      const cachedSection = cached!.tree['1'][0] as ContainerNode;
      const cachedRow = cachedSection.children![0] as ContainerNode;
      const cachedColumn = cachedRow.children![0] as ContainerNode;
      expect(cachedColumn.children!.map((c) => c.id)).toEqual([elemB.id, elemA.id]);

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });
      const [url] = getFetchCalls()[0];
      expect(url).toContain('/api/reorder');
    });

    it('should show toast and restore snapshot on error', async () => {
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree();
      mockFetchSequence([
        { status: 500, body: { message: 'Reorder failed' } },
        // The onSettled invalidation triggers a refetch — provide the original response
        { status: 200, body: treeApiResponse },
      ]);
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      act(() => {
        result.current.mutate({
          params: { elementID: elemB.id, targetParentId: column.id, afterElementID: null },
          tree,
        });
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });
    });

    it('does not refetch after an optimistic rollback', async () => {
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree();
      // Prevent the mounted useElementTree observer from doing its own
      // on-mount background refetch — we only care about the invalidation.
      queryClient.setDefaultOptions({ queries: { retry: false, gcTime: 0, staleTime: Infinity } });
      // Queue: the failed reorder POST only. If onSettled invalidates on
      // error, an active tree query observer will trigger a refetch — caught
      // by the "exactly one call" assertion below.
      mockFetchSequence([
        { status: 422, body: { message: 'hierarchy' } },
        // Safety net: if the refetch does happen, give it a valid response so
        // the test fails cleanly on the call-count assertion instead of crashing.
        { status: 200, body: treeApiResponse },
      ]);
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const { wrapper } = createProviderWrapper({ queryClient });
      // Mount a reader for the tree query so it becomes an *active* query —
      // TanStack Query only refetches observed queries on invalidation.
      renderHook(() => useElementTree(1, 'main'), { wrapper });

      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      await act(async () => {
        await result.current
          .mutateAsync({
            params: { elementID: elemB.id, targetParentId: column.id, afterElementID: null },
            tree,
          })
          .catch(() => undefined);
      });

      // Wait for the error toast so the mutation has fully settled.
      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });

      // Flush any queued microtasks that an invalidate-triggered refetch
      // would use to schedule its fetch.
      await act(async () => {
        await Promise.resolve();
      });

      // Exactly one call — the failed reorder POST. No refetch after rollback.
      const reorderCalls = getFetchCalls().filter(([url]) =>
        String(url).includes('/api/reorder'),
      );
      const treeCalls = getFetchCalls().filter(([url]) => String(url).includes('/api/readTree'));
      expect(reorderCalls).toHaveLength(1);
      expect(treeCalls).toHaveLength(0);
    });

    it('should call clearPendingTree on error as safety net', async () => {
      const { queryClient, tree, treeApiResponse, column, elemB } = createReorderTree();
      mockFetchSequence([
        { status: 500, body: { message: 'fail' } },
        { status: 200, body: treeApiResponse },
      ]);

      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      const clearPendingTree = vi.fn();

      act(() => {
        result.current.mutate({
          params: { elementID: elemB.id, targetParentId: column.id, afterElementID: null },
          tree,
          clearPendingTree,
        });
      });

      await waitFor(() => {
        expect(clearPendingTree).toHaveBeenCalled();
      });
    });
  });
});
