import { useQuery } from '@tanstack/react-query'
import { fetchElementTree } from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
import type { TreeApiResponse } from '@/types/elements'
import { countOverrides } from '@/utils/countOverrides'
import { queryKeys } from './queryKeys'

function treeQueryOptions(pageId: number | null, zone: string, version?: number) {
  return {
    queryKey:
      pageId !== null
        ? queryKeys.elementTree.byPage(pageId, zone, version)
        : (['elementTree', 'disabled'] as const),
    queryFn: () => {
      if (pageId === null) {
        // Stryker disable next-line StringLiteral: Equivalent — defensive invariant; queryFn only runs when `enabled` is true (pageId !== null), so this throw is unreachable and its message is never observable
        throw new Error('pageId is required — query should be disabled')
      }
      return fetchElementTree(pageId, zone, version)
    },
    enabled: pageId !== null,
    // Archived versions are immutable server-side, so a version-specific tree never
    // goes stale: mark it fresh forever so remounting (e.g. stepping through the
    // history viewer) and window refocus reuse the cache instead of refetching.
    // Note this does NOT suppress refetches from queryClient.invalidateQueries() —
    // invalidation sets isInvalidated, which overrides staleTime.
    ...(version !== undefined ? { staleTime: Number.POSITIVE_INFINITY } : {}),
  }
}

/**
 * Fetches and caches the element tree for a CMS page zone.
 * Disabled when pageId is null (no page selected).
 */
export function useElementTree(pageId: number | null, zone: string, version?: number) {
  return useQuery<TreeApiResponse, ApiError>({
    ...treeQueryOptions(pageId, zone, version),
  })
}

// Module-scope so the select identity is stable: TanStack re-runs `select`
// whenever data OR the select function changes, and an inline arrow would
// re-walk the tree on every consumer render instead of only on new data.
const selectOverrideCounts = (response: TreeApiResponse): Record<string, number> =>
  countOverrides(response.nodes)

/**
 * Derives per-viewport grid-settings override counts from the cached tree.
 * Shares the same query cache as {@link useElementTree} — no extra fetch.
 * Pass `version` to read from a version-specific cache entry.
 */
export function useViewportOverrideCounts(
  pageId: number | null,
  zone: string,
  version?: number,
): Record<string, number> {
  const { data } = useQuery<TreeApiResponse, ApiError, Record<string, number>>({
    ...treeQueryOptions(pageId, zone, version),
    select: selectOverrideCounts,
  })

  return data ?? {}
}
