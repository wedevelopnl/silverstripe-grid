import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useElementTree, useViewportOverrideCounts } from '@/hooks/useElementTree';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import { createTreeApiResponse, resetIdCounter } from '@/testing/factories';
import { queryKeys } from '@/hooks/queryKeys';
import { QueryClient } from '@tanstack/react-query';

describe('useElementTree', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  it('should fetch and select tree when pageId is provided', async () => {
    const apiResponse = createTreeApiResponse();
    mockFetchSuccess(apiResponse);
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main' });

    const { result } = renderHook(() => useElementTree(1, 'main'), { wrapper });

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true);
    });

    expect(result.current.data).toEqual(apiResponse.tree);
    const [url] = getFetchCalls()[0];
    expect(url).toContain('/api/readTree/1/main');
  });

  it('should not fetch when pageId is null', () => {
    mockFetchSuccess({});
    const { wrapper } = createProviderWrapper();
    const { result } = renderHook(() => useElementTree(null, 'main'), { wrapper });

    expect(result.current.isFetching).toBe(false);
    expect(result.current.data).toBeUndefined();
    expect(getFetchCalls()).toHaveLength(0);
  });
});

describe('useViewportOverrideCounts', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  it('should return overrideCounts from cached response', async () => {
    const overrideCounts = { md: 3, lg: 1 };
    const apiResponse = createTreeApiResponse({ overrideCounts });

    // Pre-seed the cache so useViewportOverrideCounts can select from it
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });
    queryClient.setQueryData(
      queryKeys.elementTree.byPage(1, 'main'),
      apiResponse,
    );

    const { wrapper } = createProviderWrapper({ queryClient, pageId: 1, zone: 'main' });
    const { result } = renderHook(
      () => useViewportOverrideCounts(1, 'main'),
      { wrapper },
    );

    await waitFor(() => {
      expect(result.current).toEqual({ md: 3, lg: 1 });
    });
  });

  it('should return empty object when no data is cached', () => {
    mockFetchSuccess({});
    const { wrapper } = createProviderWrapper();
    const { result } = renderHook(
      () => useViewportOverrideCounts(null, 'main'),
      { wrapper },
    );

    expect(result.current).toEqual({});
  });
});
