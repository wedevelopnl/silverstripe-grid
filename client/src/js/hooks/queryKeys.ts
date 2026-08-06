export const queryKeys = {
  elementTree: {
    byPage: (pageId: number, zone: string, version?: number) =>
      version !== undefined
        ? (['elementTree', pageId, zone, version] as const)
        : (['elementTree', pageId, zone] as const),
  },
  pages: {
    search: (search: string) => ['pages', search] as const,
  },
  zones: {
    byPage: (pageId: number) => ['zones', pageId] as const,
  },
  acceptableContainers: {
    all: () => ['acceptableContainers'] as const,
    byTarget: (pageId: number, zone: string, elementType: string) =>
      ['acceptableContainers', pageId, zone, elementType] as const,
  },
} as const
