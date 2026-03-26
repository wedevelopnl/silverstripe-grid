import { renderHook, waitFor } from '@testing-library/react';
import { useElementTree } from '@/hooks/useElementTree';
import type { ElementTreeResponse } from '@/types/elements';
import { createQueryWrapper } from '../helpers/dndTestUtils';

const mockFetchElementTree = vi.fn();

vi.mock('@/api/endpoints', () => ({
  fetchElementTree: (...args: unknown[]) => mockFetchElementTree(...args),
  updateGridSettings: vi.fn(),
}));

describe('useElementTree', () => {
  afterEach(() => {
    mockFetchElementTree.mockReset();
  });

  it('fetches element tree for given pageId', async () => {
    const mockTree: ElementTreeResponse = {
      '42': [],
    };
    mockFetchElementTree.mockResolvedValue(mockTree);

    const { result } = renderHook(() => useElementTree(42, 'main'), {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(mockFetchElementTree).toHaveBeenCalledWith(42, 'main');
    expect(result.current.data).toEqual(mockTree);
  });

  it('does not fetch when pageId is null', () => {
    mockFetchElementTree.mockResolvedValue({});

    const { result } = renderHook(() => useElementTree(null, 'main'), {
      wrapper: createQueryWrapper().wrapper,
    });

    expect(result.current.fetchStatus).toBe('idle');
    expect(mockFetchElementTree).not.toHaveBeenCalled();
  });

  it('exposes error when fetch fails', async () => {
    mockFetchElementTree.mockRejectedValue(new Error('Network error'));

    const { result } = renderHook(() => useElementTree(1, 'main'), {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(result.current.error).toBeInstanceOf(Error);
  });

  it('uses distinct query keys for different pages so caches do not collide', async () => {
    const tree42: ElementTreeResponse = { '42': [] };
    const tree99: ElementTreeResponse = { '99': [] };
    mockFetchElementTree
      .mockResolvedValueOnce(tree42)
      .mockResolvedValueOnce(tree99);

    const wrapper = createQueryWrapper().wrapper;

    const hook42 = renderHook(() => useElementTree(42, 'main'), { wrapper });
    await waitFor(() => expect(hook42.result.current.isSuccess).toBe(true));

    const hook99 = renderHook(() => useElementTree(99, 'main'), { wrapper });
    await waitFor(() => expect(hook99.result.current.isSuccess).toBe(true));

    // Each hook fetched independently — proves query keys differ
    expect(mockFetchElementTree).toHaveBeenCalledTimes(2);
    expect(hook42.result.current.data).toEqual(tree42);
    expect(hook99.result.current.data).toEqual(tree99);
  });

  it('uses a stable disabled key when pageId is null (does not interfere with real queries)', async () => {
    const tree1: ElementTreeResponse = { '1': [] };
    mockFetchElementTree.mockResolvedValue(tree1);

    const wrapper = createQueryWrapper().wrapper;

    // First render with null — should not fetch
    const hookNull = renderHook(() => useElementTree(null, 'main'), { wrapper });
    expect(hookNull.result.current.fetchStatus).toBe('idle');

    // Second render with real page — should fetch
    const hook1 = renderHook(() => useElementTree(1, 'main'), { wrapper });
    await waitFor(() => expect(hook1.result.current.isSuccess).toBe(true));

    // The null hook should still be idle (disabled key didn't share cache with page 1)
    expect(hookNull.result.current.fetchStatus).toBe('idle');
    expect(hookNull.result.current.data).toBeUndefined();
  });
});
