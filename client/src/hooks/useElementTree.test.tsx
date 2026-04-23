import { describe, it, expect } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useElementTree, useViewportOverrideCounts } from '@/hooks/useElementTree';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories';
import { queryKeys } from '@/hooks/queryKeys';
import { QueryClient } from '@tanstack/react-query';
import type { ColumnNode, TreeApiResponse, ViewportSettings } from '@/types/elements';

const override: ViewportSettings = { width: 6, offset: 0, visible: true };
const defaults: ViewportSettings = { width: 12, offset: 0, visible: true };

function treeWithOverrides(spec: Record<string, number>): TreeApiResponse {
  const columns: ColumnNode[] = [];
  for (const [viewport, count] of Object.entries(spec)) {
    for (let i = 0; i < count; i++) {
      columns.push(
        createColumnNode({
          gridSettings: { default: defaults, overrides: { [viewport]: override } },
          children: [],
        }),
      );
    }
  }
  const row = createRowNode({ children: columns });
  const section = createSectionNode({ parent: { type: 'page', id: 1 }, children: [row] });
  return createTreeApiResponse({ pageId: 1, sections: [section] });
}

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

    expect(result.current.data?.nodes).toHaveLength(apiResponse.nodes.length);
    expect(result.current.data?.rootParent).toEqual(apiResponse.rootParent);
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

  it('should append /version/N path segment when version is provided', async () => {
    const apiResponse = createTreeApiResponse();
    mockFetchSuccess(apiResponse);
    const { wrapper } = createProviderWrapper({ pageId: 1, zone: 'main' });

    const { result } = renderHook(() => useElementTree(1, 'main', 5), { wrapper });

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true);
    });

    const [url] = getFetchCalls()[0];
    expect(url).toContain('/api/readTree/1/main/version/5');
  });
});

describe('useViewportOverrideCounts', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  it('should derive counts from cached tree nodes', async () => {
    // 3 md-override columns, 1 lg-override column → _total=4, md=3, lg=1
    const apiResponse = treeWithOverrides({ md: 3, lg: 1 });

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), apiResponse);

    const { wrapper } = createProviderWrapper({ queryClient, pageId: 1, zone: 'main' });
    const { result } = renderHook(() => useViewportOverrideCounts(1, 'main'), { wrapper });

    await waitFor(() => {
      expect(result.current).toEqual({ _total: 4, md: 3, lg: 1 });
    });
  });

  it('should return empty object when no data is cached', () => {
    mockFetchSuccess({});
    const { wrapper } = createProviderWrapper();
    const { result } = renderHook(() => useViewportOverrideCounts(null, 'main'), { wrapper });

    expect(result.current).toEqual({});
  });

  it('should use version-keyed cache entry when version is provided', async () => {
    const apiResponse = treeWithOverrides({ md: 2 });

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });
    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main', 5), apiResponse);

    const { wrapper } = createProviderWrapper({ queryClient, pageId: 1, zone: 'main' });
    const { result } = renderHook(() => useViewportOverrideCounts(1, 'main', 5), { wrapper });

    await waitFor(() => {
      expect(result.current).toEqual({ _total: 2, md: 2 });
    });
  });
});
