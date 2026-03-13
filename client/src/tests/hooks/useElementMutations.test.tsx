import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import {
  useCreateElement,
  useCreateContentElement,
  usePublishElement,
  useUnpublishElement,
  useArchiveElement,
  useDuplicateElement,
  useUpdateGridSettings,
  useReorderElement,
} from '@/hooks/useElementMutations';
import { queryKeys } from '@/hooks/queryKeys';

const mockCreateElement = vi.fn();
const mockCreateContentElement = vi.fn();
const mockPublishElement = vi.fn();
const mockUnpublishElement = vi.fn();
const mockArchiveElement = vi.fn();
const mockDuplicateElement = vi.fn();
const mockUpdateGridSettings = vi.fn();
const mockReorderElement = vi.fn();
const mockShowToast = vi.fn();
const mockApplyReorder = vi.fn();

vi.mock('@/utils/toast', () => ({
  showToast: (...args: unknown[]) => mockShowToast(...args),
}));

vi.mock('@/api/endpoints', () => ({
  createElement: (...args: unknown[]) => mockCreateElement(...args),
  createContentElement: (...args: unknown[]) => mockCreateContentElement(...args),
  publishElement: (...args: unknown[]) => mockPublishElement(...args),
  unpublishElement: (...args: unknown[]) => mockUnpublishElement(...args),
  archiveElement: (...args: unknown[]) => mockArchiveElement(...args),
  duplicateElement: (...args: unknown[]) => mockDuplicateElement(...args),
  updateGridSettings: (...args: unknown[]) => mockUpdateGridSettings(...args),
  reorderElement: (...args: unknown[]) => mockReorderElement(...args),
}));

vi.mock('@/utils/applyReorder', () => ({
  applyReorder: (...args: unknown[]) => mockApplyReorder(...args),
}));

let queryClient: QueryClient;

function createWrapper() {
  queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        {children}
      </QueryClientProvider>
    );
  };
}

describe('useCreateElement', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    mockCreateElement.mockReset();
  });

  it('calls createElement endpoint with params as first argument', async () => {
    mockCreateElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => useCreateElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() =>
      result.current.mutateAsync({
        containerType: 'section',
        parentId: 10,
      }),
    );

    // TanStack Query v5 passes (variables, { client, meta, mutationKey })
    expect(mockCreateElement).toHaveBeenCalledWith(
      { containerType: 'section', parentId: 10 },
      expect.anything(),
    );
  });

  it('invalidates element tree cache on success', async () => {
    mockCreateElement.mockResolvedValue(undefined);
    const wrapper = createWrapper();
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    const { result } = renderHook(() => useCreateElement(42, 'main'), { wrapper });

    await act(() =>
      result.current.mutateAsync({
        containerType: 'section',
        parentId: 10,
      }),
    );

    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(42, 'main'),
      }),
    );
  });
});

describe('useCreateContentElement', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    mockCreateContentElement.mockReset();
  });

  it('calls createContentElement endpoint with params', async () => {
    mockCreateContentElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => useCreateContentElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() =>
      result.current.mutateAsync({
        className: 'SilverStripe\\ElementalBlocks\\Block\\ContentBlock',
        parentId: 10,
        insertAfterElementID: 5,
      }),
    );

    expect(mockCreateContentElement).toHaveBeenCalledWith(
      {
        className: 'SilverStripe\\ElementalBlocks\\Block\\ContentBlock',
        parentId: 10,
        insertAfterElementID: 5,
      },
      expect.anything(),
    );
  });

  it('invalidates element tree cache on success', async () => {
    mockCreateContentElement.mockResolvedValue(undefined);
    const wrapper = createWrapper();
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    const { result } = renderHook(() => useCreateContentElement(42, 'main'), { wrapper });

    await act(() =>
      result.current.mutateAsync({
        className: 'SilverStripe\\ElementalBlocks\\Block\\ContentBlock',
        parentId: 10,
      }),
    );

    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(42, 'main'),
      }),
    );
  });
});

describe('usePublishElement', () => {
  afterEach(() => {
    mockPublishElement.mockReset();
  });

  it('calls publishElement endpoint', async () => {
    mockPublishElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => usePublishElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() => result.current.mutateAsync(7));

    expect(mockPublishElement).toHaveBeenCalledWith(7, expect.anything());
  });
});

describe('useUnpublishElement', () => {
  afterEach(() => {
    mockUnpublishElement.mockReset();
  });

  it('calls unpublishElement endpoint', async () => {
    mockUnpublishElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => useUnpublishElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() => result.current.mutateAsync(7));

    expect(mockUnpublishElement).toHaveBeenCalledWith(7, expect.anything());
  });
});

describe('useArchiveElement', () => {
  afterEach(() => {
    mockArchiveElement.mockReset();
  });

  it('calls archiveElement endpoint', async () => {
    mockArchiveElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => useArchiveElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() => result.current.mutateAsync(3));

    expect(mockArchiveElement).toHaveBeenCalledWith(3, expect.anything());
  });
});

describe('useDuplicateElement', () => {
  afterEach(() => {
    mockDuplicateElement.mockReset();
  });

  it('calls duplicateElement endpoint and invalidates cache', async () => {
    mockDuplicateElement.mockResolvedValue(undefined);
    const wrapper = createWrapper();
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    const { result } = renderHook(() => useDuplicateElement(42, 'main'), { wrapper });

    await act(() => result.current.mutateAsync(9));

    expect(mockDuplicateElement).toHaveBeenCalledWith(9, expect.anything());
    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(42, 'main'),
      }),
    );
  });

  it('exposes error when mutation fails', async () => {
    const error = new Error('Server error');
    mockDuplicateElement.mockRejectedValue(error);
    const { result } = renderHook(() => useDuplicateElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(async () => {
      try {
        await result.current.mutateAsync(9);
      } catch {
        // Expected — error captured by TanStack Query
      }
    });

    await waitFor(() => expect(result.current.error).toBe(error));
  });
});

describe('useUpdateGridSettings', () => {
  afterEach(() => {
    mockUpdateGridSettings.mockReset();
    mockShowToast.mockReset();
  });

  it('calls updateGridSettings endpoint with params', async () => {
    mockUpdateGridSettings.mockResolvedValue(undefined);
    const { result } = renderHook(() => useUpdateGridSettings(42, 'main'), {
      wrapper: createWrapper(),
    });

    const params = { id: 5, viewport: 'md', width: 6, offset: 0, visible: true };
    await act(() => result.current.mutateAsync(params));

    expect(mockUpdateGridSettings).toHaveBeenCalledWith(params, expect.anything());
  });

  it('invalidates element tree cache on success', async () => {
    mockUpdateGridSettings.mockResolvedValue(undefined);
    const wrapper = createWrapper();
    const invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    const { result } = renderHook(() => useUpdateGridSettings(42, 'main'), { wrapper });

    await act(() =>
      result.current.mutateAsync({ id: 5, viewport: 'md', width: 6, offset: 0, visible: true }),
    );

    await waitFor(() =>
      expect(invalidateSpy).toHaveBeenCalledWith({
        queryKey: queryKeys.elementTree.byPage(42, 'main'),
      }),
    );
  });

  it('shows toast on mutation error', async () => {
    const error = new Error('Bad Request');
    mockUpdateGridSettings.mockRejectedValue(error);
    const { result } = renderHook(() => useUpdateGridSettings(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(async () => {
      try {
        await result.current.mutateAsync({ id: 5, viewport: 'md', width: 6, offset: 0, visible: true });
      } catch {
        // Expected — error captured by TanStack Query
      }
    });

    await waitFor(() => expect(mockShowToast).toHaveBeenCalledWith('Bad Request'));
  });
});

describe('useReorderElement', () => {
  afterEach(() => {
    mockReorderElement.mockReset();
    mockApplyReorder.mockReset();
    mockShowToast.mockReset();
  });

  const tree = { '42': [] };
  const optimisticTree = { '42': [{ moved: true }] };
  const params = { elementID: 30, targetParentId: 20, afterElementID: 31 };

  it('cancels pending queries before applying optimistic update', async () => {
    mockReorderElement.mockResolvedValue(undefined);
    mockApplyReorder.mockReturnValue(optimisticTree);
    const wrapper = createWrapper();
    const cancelSpy = vi.spyOn(queryClient, 'cancelQueries');
    const { result } = renderHook(() => useReorderElement(42, 'main'), { wrapper });

    await act(() =>
      result.current.mutateAsync({ params, tree }),
    );

    expect(cancelSpy).toHaveBeenCalledWith({
      queryKey: queryKeys.elementTree.byPage(42, 'main'),
    });
  });

  it('sets optimistic data in query cache during mutation', async () => {
    mockReorderElement.mockResolvedValue(undefined);
    mockApplyReorder.mockReturnValue(optimisticTree);
    const wrapper = createWrapper();
    const setDataSpy = vi.spyOn(queryClient, 'setQueryData');
    const { result } = renderHook(() => useReorderElement(42, 'main'), { wrapper });

    await act(() =>
      result.current.mutateAsync({ params, tree }),
    );

    // First setQueryData call is the optimistic update
    expect(setDataSpy).toHaveBeenCalledWith(
      queryKeys.elementTree.byPage(42, 'main'),
      optimisticTree,
    );
  });

  it('rolls back to snapshot on error when snapshot exists', async () => {
    const snapshotTree = { '42': [{ original: true }] };
    mockReorderElement.mockRejectedValue(new Error('Reorder failed'));
    mockApplyReorder.mockReturnValue(optimisticTree);
    const wrapper = createWrapper();

    // Seed the cache with a snapshot so onMutate captures it
    queryClient.setQueryData(queryKeys.elementTree.byPage(42, 'main'), snapshotTree);

    const setDataSpy = vi.spyOn(queryClient, 'setQueryData');
    const { result } = renderHook(() => useReorderElement(42, 'main'), { wrapper });

    await act(async () => {
      try {
        await result.current.mutateAsync({ params, tree });
      } catch {
        // Expected
      }
    });

    // The last setQueryData before invalidation should restore the snapshot
    await waitFor(() => expect(mockShowToast).toHaveBeenCalledWith('Reorder failed'));
    const setDataCalls = setDataSpy.mock.calls.filter(
      (call) => JSON.stringify(call[0]) === JSON.stringify(queryKeys.elementTree.byPage(42, 'main')),
    );
    // Should have: optimistic update, then snapshot rollback
    expect(setDataCalls.length).toBeGreaterThanOrEqual(2);
    expect(setDataCalls[1][1]).toEqual(snapshotTree);
  });

  it('does not roll back when no snapshot exists (undefined)', async () => {
    mockReorderElement.mockRejectedValue(new Error('Reorder failed'));
    mockApplyReorder.mockReturnValue(optimisticTree);
    const wrapper = createWrapper();

    // Do NOT seed the cache — snapshot will be undefined
    const setDataSpy = vi.spyOn(queryClient, 'setQueryData');
    const { result } = renderHook(() => useReorderElement(42, 'main'), { wrapper });

    await act(async () => {
      try {
        await result.current.mutateAsync({ params, tree });
      } catch {
        // Expected
      }
    });

    await waitFor(() => expect(mockShowToast).toHaveBeenCalledWith('Reorder failed'));
    // setQueryData should only be called once (optimistic), no rollback
    const setDataCalls = setDataSpy.mock.calls.filter(
      (call) => JSON.stringify(call[0]) === JSON.stringify(queryKeys.elementTree.byPage(42, 'main')),
    );
    expect(setDataCalls).toHaveLength(1);
  });
});
