import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import {
  useCreateContentElement,
  useUpdateGridSettings,
  useResetGridSettingsOverrides,
  useReorderElement,
} from '@/hooks/useElementMutations';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createTree, createTreeApiResponse, resetIdCounter } from '@/testing/factories';
import { mockFetchSuccess, mockFetchError, mockFetchSequence, getFetchCalls } from '@/testing/mockFetch';
import { queryKeys } from '@/hooks/queryKeys';
import { QueryClient } from '@tanstack/react-query';

function createQueryClientWithTree(pageId = 1, zone = 'main') {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });
  queryClient.setQueryData(
    queryKeys.elementTree.byPage(pageId, zone),
    createTreeApiResponse(),
  );
  return queryClient;
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

  describe('useReorderElement', () => {
    it('should call reorder endpoint', async () => {
      mockFetchSuccess({});
      const queryClient = createQueryClientWithTree();
      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      const tree = createTree();
      act(() => {
        result.current.mutate({
          params: { elementID: 10, targetParentId: 20, afterElementID: null },
          tree,
        });
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url] = getFetchCalls()[0];
      expect(url).toContain('/api/reorder');
    });

    it('should show toast and restore snapshot on error', async () => {
      mockFetchSequence([
        { status: 500, body: { message: 'Reorder failed' } },
        // The onSettled invalidation triggers a refetch — provide a success response
        { status: 200, body: createTreeApiResponse() },
      ]);
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const queryClient = createQueryClientWithTree();
      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      const tree = createTree();
      act(() => {
        result.current.mutate({
          params: { elementID: 10, targetParentId: 20, afterElementID: null },
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

    it('should call clearPendingTree on error as safety net', async () => {
      mockFetchSequence([
        { status: 500, body: { message: 'fail' } },
        { status: 200, body: createTreeApiResponse() },
      ]);

      const queryClient = createQueryClientWithTree();
      const { wrapper } = createProviderWrapper({ queryClient });
      const { result } = renderHook(() => useReorderElement(1, 'main'), { wrapper });

      const clearPendingTree = vi.fn();
      const tree = createTree();

      act(() => {
        result.current.mutate({
          params: { elementID: 10, targetParentId: 20, afterElementID: null },
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
