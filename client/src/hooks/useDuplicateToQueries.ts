import { skipToken, useQuery } from '@tanstack/react-query';
import { fetchPages, fetchZones, fetchAcceptableContainers } from '@/api/endpoints';
import type { PageEntry, AcceptableContainer } from '@/api/endpoints';
import { queryKeys } from './queryKeys';

export function usePages(search: string, enabled = true) {
  return useQuery<PageEntry[]>({
    queryKey: queryKeys.pages.search(search),
    queryFn: () => fetchPages(search || undefined),
    enabled,
  });
}

export function useZones(pageId: number | null) {
  return useQuery<string[]>({
    queryKey: queryKeys.zones.byPage(pageId ?? 0),
    queryFn: pageId !== null ? () => fetchZones(pageId) : skipToken,
  });
}

export function useAcceptableContainers(
  pageId: number | null,
  zone: string | null,
  elementType: string | null,
) {
  const allPresent = pageId !== null && zone !== null && elementType !== null;
  return useQuery<AcceptableContainer[]>({
    queryKey: queryKeys.acceptableContainers.byTarget(pageId ?? 0, zone ?? '', elementType ?? ''),
    queryFn: allPresent ? () => fetchAcceptableContainers(pageId, zone, elementType) : skipToken,
  });
}
