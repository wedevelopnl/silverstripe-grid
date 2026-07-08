// Disabled queries need a sentinel key that cannot equal any active key. Every
// disabled() key is 3 segments ending in `null`, which no active key produces
// (`pages.search('disabled')` is 2 segments, so it can no longer collide), and
// they live here so the whole keyspace is defined in one place.
export const queryKeys = {
  elementTree: {
    all: () => ['elementTree'] as const,
    byPage: (pageId: number, zone: string, version?: number) =>
      version !== undefined
        ? (['elementTree', pageId, zone, version] as const)
        : (['elementTree', pageId, zone] as const),
    disabled: () => ['elementTree', 'disabled', null] as const,
  },
  pages: {
    all: () => ['pages'] as const,
    search: (search: string) => ['pages', search] as const,
    disabled: () => ['pages', 'disabled', null] as const,
  },
  zones: {
    byPage: (pageId: number) => ['zones', pageId] as const,
    disabled: () => ['zones', 'disabled', null] as const,
  },
  acceptableContainers: {
    all: () => ['acceptableContainers'] as const,
    byTarget: (pageId: number, zone: string, elementType: string) =>
      ['acceptableContainers', pageId, zone, elementType] as const,
    disabled: () => ['acceptableContainers', 'disabled', null] as const,
  },
} as const
