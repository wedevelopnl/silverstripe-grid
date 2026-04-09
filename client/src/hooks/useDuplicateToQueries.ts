import { useQuery } from '@tanstack/react-query';
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
    queryFn: () => fetchZones(pageId!),
    enabled: pageId !== null,
  });
}

export function useAcceptableContainers(
  pageId: number | null,
  zone: string | null,
  elementType: string | null,
) {
  return useQuery<AcceptableContainer[]>({
    queryKey: queryKeys.acceptableContainers.byTarget(pageId ?? 0, zone ?? '', elementType ?? ''),
    queryFn: () => fetchAcceptableContainers(pageId!, zone!, elementType!),
    enabled: pageId !== null && zone !== null && elementType !== null,
  });
}
