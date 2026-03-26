import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import { usePages, useZones, useAcceptableContainers } from '@/hooks/useDuplicateToQueries';

const { mockFetchPages, mockFetchZones, mockFetchAcceptableContainers } = vi.hoisted(() => ({
  mockFetchPages: vi.fn(),
  mockFetchZones: vi.fn(),
  mockFetchAcceptableContainers: vi.fn(),
}));

vi.mock('@/api/endpoints', () => ({
  fetchPages: (...args: unknown[]) => mockFetchPages(...args),
  fetchZones: (...args: unknown[]) => mockFetchZones(...args),
  fetchAcceptableContainers: (...args: unknown[]) => mockFetchAcceptableContainers(...args),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
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

describe('useDuplicateToQueries', () => {
  beforeEach(() => {
    mockFetchPages.mockResolvedValue([]);
    mockFetchZones.mockResolvedValue([]);
    mockFetchAcceptableContainers.mockResolvedValue([]);
  });

  afterEach(() => {
    mockFetchPages.mockReset();
    mockFetchZones.mockReset();
    mockFetchAcceptableContainers.mockReset();
  });

  describe('usePages', () => {
    it('fetches with search term', async () => {
      const { result } = renderHook(() => usePages('foo'), {
        wrapper: createWrapper(),
      });

      await waitFor(() => expect(result.current.isSuccess).toBe(true));

      expect(mockFetchPages).toHaveBeenCalledWith('foo');
    });

    it('passes undefined when search is empty', async () => {
      const { result } = renderHook(() => usePages(''), {
        wrapper: createWrapper(),
      });

      await waitFor(() => expect(result.current.isSuccess).toBe(true));

      expect(mockFetchPages).toHaveBeenCalledWith(undefined);
    });

    it('does not fetch when enabled is false', () => {
      const { result } = renderHook(() => usePages('foo', false), {
        wrapper: createWrapper(),
      });

      expect(result.current.fetchStatus).toBe('idle');
      expect(mockFetchPages).not.toHaveBeenCalled();
    });
  });

  describe('useZones', () => {
    it('fetches when pageId is provided', async () => {
      const { result } = renderHook(() => useZones(42), {
        wrapper: createWrapper(),
      });

      await waitFor(() => expect(result.current.isSuccess).toBe(true));

      expect(mockFetchZones).toHaveBeenCalledWith(42);
    });

    it('does not fetch when pageId is null', () => {
      const { result } = renderHook(() => useZones(null), {
        wrapper: createWrapper(),
      });

      expect(result.current.fetchStatus).toBe('idle');
      expect(mockFetchZones).not.toHaveBeenCalled();
    });
  });

  describe('useAcceptableContainers', () => {
    it('fetches when all params are non-null', async () => {
      const { result } = renderHook(
        () => useAcceptableContainers(42, 'main', 'row'),
        { wrapper: createWrapper() },
      );

      await waitFor(() => expect(result.current.isSuccess).toBe(true));

      expect(mockFetchAcceptableContainers).toHaveBeenCalledWith(42, 'main', 'row');
    });

    it('does not fetch when pageId is null', () => {
      const { result } = renderHook(
        () => useAcceptableContainers(null, 'main', 'row'),
        { wrapper: createWrapper() },
      );

      expect(result.current.fetchStatus).toBe('idle');
      expect(mockFetchAcceptableContainers).not.toHaveBeenCalled();
    });

    it('does not fetch when zone is null', () => {
      const { result } = renderHook(
        () => useAcceptableContainers(42, null, 'row'),
        { wrapper: createWrapper() },
      );

      expect(result.current.fetchStatus).toBe('idle');
      expect(mockFetchAcceptableContainers).not.toHaveBeenCalled();
    });

    it('does not fetch when elementType is null', () => {
      const { result } = renderHook(
        () => useAcceptableContainers(42, 'main', null),
        { wrapper: createWrapper() },
      );

      expect(result.current.fetchStatus).toBe('idle');
      expect(mockFetchAcceptableContainers).not.toHaveBeenCalled();
    });
  });
});
