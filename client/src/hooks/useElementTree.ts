import { useQuery } from '@tanstack/react-query';
import { fetchElementTree } from '@/api/endpoints';
import type { ElementTreeResponse, TreeApiResponse } from '@/types/elements';
import type { ApiError } from '@/api/errors';
import { queryKeys } from './queryKeys';

/**
 * Fetches and caches the element tree for a CMS page zone.
 * Disabled when pageId is null (no page selected).
 *
 * Uses `select` to extract just the tree, keeping the full API response
 * (including overrideCounts) in the query cache for other hooks.
 */
export function useElementTree(pageId: number | null, zone: string) {
  return useQuery<TreeApiResponse, ApiError, ElementTreeResponse>({
    queryKey: pageId !== null
      ? queryKeys.elementTree.byPage(pageId, zone)
      : ['elementTree', 'disabled'],
    queryFn: () => {
      if (pageId === null) {
        throw new Error('pageId is required — query should be disabled');
      }
      return fetchElementTree(pageId, zone);
    },
    select: (response) => response.tree,
    enabled: pageId !== null,
  });
}

/**
 * Reads per-viewport override counts from the cached tree API response.
 * Shares the same query cache as useElementTree — no extra fetch.
 */
export function useViewportOverrideCounts(pageId: number | null, zone: string): Record<string, number> {
  const { data } = useQuery<TreeApiResponse, ApiError, Record<string, number>>({
    queryKey: pageId !== null
      ? queryKeys.elementTree.byPage(pageId, zone)
      : ['elementTree', 'disabled'],
    queryFn: () => {
      if (pageId === null) {
        throw new Error('pageId is required — query should be disabled');
      }
      return fetchElementTree(pageId, zone);
    },
    select: (response) => response.overrideCounts,
    enabled: pageId !== null,
  });

  return data ?? {};
}
