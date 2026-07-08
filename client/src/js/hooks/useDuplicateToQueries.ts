import { skipToken, useQuery } from '@tanstack/react-query'
import type { AcceptableContainer, PageEntry } from '@/api/endpoints'
import { fetchAcceptableContainers, fetchPages, fetchZones } from '@/api/endpoints'
import { queryKeys } from './queryKeys'

export function usePages(search: string, enabled = true) {
  return useQuery<PageEntry[]>({
    queryKey: enabled ? queryKeys.pages.search(search) : queryKeys.pages.disabled(),
    queryFn: enabled ? () => fetchPages(search || undefined) : skipToken,
  })
}

// Disabled queries use a distinct sentinel key (see queryKeys.*.disabled) so they
// don't collide with an active key in the cache. Collisions would otherwise leak
// cached data across unrelated calls — e.g. searching pages for "disabled".
export function useZones(pageId: number | null) {
  return useQuery<string[]>({
    queryKey: pageId !== null ? queryKeys.zones.byPage(pageId) : queryKeys.zones.disabled(),
    queryFn: pageId !== null ? () => fetchZones(pageId) : skipToken,
  })
}

export function useAcceptableContainers(
  pageId: number | null,
  zone: string | null,
  elementType: string | null,
) {
  const allPresent = pageId !== null && zone !== null && elementType !== null
  return useQuery<AcceptableContainer[]>({
    queryKey: allPresent
      ? queryKeys.acceptableContainers.byTarget(pageId, zone, elementType)
      : queryKeys.acceptableContainers.disabled(),
    queryFn: allPresent ? () => fetchAcceptableContainers(pageId, zone, elementType) : skipToken,
  })
}
