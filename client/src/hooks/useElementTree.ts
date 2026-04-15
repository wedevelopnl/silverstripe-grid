import { useQuery } from '@tanstack/react-query';
import { fetchElementTree } from '@/api/endpoints';
import type { TreeApiResponse } from '@/types/elements';
import type { ApiError } from '@/api/errors';
import { queryKeys } from './queryKeys';

function treeQueryOptions(pageId: number | null, zone: string, version?: number) {
  return {
    queryKey:
      pageId !== null
        ? queryKeys.elementTree.byPage(pageId, zone, version)
        : (['elementTree', 'disabled'] as const),
    queryFn: () => {
      if (pageId === null) {
        throw new Error('pageId is required — query should be disabled');
      }
      return fetchElementTree(pageId, zone, version);
    },
    enabled: pageId !== null,
  };
}

/**
 * Fetches and caches the element tree for a CMS page zone.
 * Disabled when pageId is null (no page selected).
 *
 * Returns the full {@link TreeApiResponse} including `rootParent`, `nodes`,
 * and `overrideCounts`.
 */
export function useElementTree(pageId: number | null, zone: string, version?: number) {
  return useQuery<TreeApiResponse, ApiError>({
    ...treeQueryOptions(pageId, zone, version),
  });
}

/**
 * Reads per-viewport override counts from the cached tree API response.
 * Shares the same query cache as useElementTree — no extra fetch.
 * Pass `version` to read from a version-specific cache entry.
 */
export function useViewportOverrideCounts(
  pageId: number | null,
  zone: string,
  version?: number,
): Record<string, number> {
  const { data } = useQuery<TreeApiResponse, ApiError, Record<string, number>>({
    ...treeQueryOptions(pageId, zone, version),
    select: (response) => response.overrideCounts,
  });

  return data ?? {};
}
