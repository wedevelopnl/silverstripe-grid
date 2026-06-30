import { skipToken, useQuery } from '@tanstack/react-query'
import type { AcceptableContainer, PageEntry } from '@/api/endpoints'
import { fetchAcceptableContainers, fetchPages, fetchZones } from '@/api/endpoints'
import { queryKeys } from './queryKeys'

export function usePages(search: string, enabled = true) {
  return useQuery<PageEntry[]>({
    queryKey: enabled ? queryKeys.pages.search(search) : (['pages', 'disabled'] as const),
    queryFn: enabled ? () => fetchPages(search || undefined) : skipToken,
  })
}

// Disabled queries use a distinct sentinel key so they don't collide with a
// real pageId of 0 (or empty zone/elementType strings) in the query cache.
// Collisions would otherwise leak cached data across unrelated calls.
export function useZones(pageId: number | null) {
  return useQuery<string[]>({
    queryKey: pageId !== null ? queryKeys.zones.byPage(pageId) : (['zones', 'disabled'] as const),
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
      : (['acceptableContainers', 'disabled'] as const),
    queryFn: allPresent ? () => fetchAcceptableContainers(pageId, zone, elementType) : skipToken,
  })
}
