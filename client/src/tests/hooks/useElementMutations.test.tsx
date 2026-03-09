import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import {
  useCreateElement,
  useCreateContentElement,
  usePublishElement,
  useUnpublishElement,
  useDeleteElement,
  useDuplicateElement,
  useUpdateGridSettings,
} from '@/hooks/useElementMutations';
import { queryKeys } from '@/hooks/queryKeys';

const mockCreateElement = vi.fn();
const mockCreateContentElement = vi.fn();
const mockPublishElement = vi.fn();
const mockUnpublishElement = vi.fn();
const mockDeleteElement = vi.fn();
const mockDuplicateElement = vi.fn();
const mockUpdateGridSettings = vi.fn();
const mockShowToast = vi.fn();

vi.mock('@/utils/toast', () => ({
  showToast: (...args: unknown[]) => mockShowToast(...args),
}));

vi.mock('@/api/endpoints', () => ({
  createElement: (...args: unknown[]) => mockCreateElement(...args),
  createContentElement: (...args: unknown[]) => mockCreateContentElement(...args),
  publishElement: (...args: unknown[]) => mockPublishElement(...args),
  unpublishElement: (...args: unknown[]) => mockUnpublishElement(...args),
  deleteElement: (...args: unknown[]) => mockDeleteElement(...args),
  duplicateElement: (...args: unknown[]) => mockDuplicateElement(...args),
  updateGridSettings: (...args: unknown[]) => mockUpdateGridSettings(...args),
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

describe('useDeleteElement', () => {
  afterEach(() => {
    mockDeleteElement.mockReset();
  });

  it('calls deleteElement endpoint', async () => {
    mockDeleteElement.mockResolvedValue(undefined);
    const { result } = renderHook(() => useDeleteElement(42, 'main'), {
      wrapper: createWrapper(),
    });

    await act(() => result.current.mutateAsync(3));

    expect(mockDeleteElement).toHaveBeenCalledWith(3, expect.anything());
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
