export const queryKeys = {
  elementTree: {
    all: () => ['elementTree'] as const,
    byPage: (pageId: number, zone: string) => ['elementTree', pageId, zone] as const,
  },
  pages: {
    all: () => ['pages'] as const,
    search: (search: string) => ['pages', search] as const,
  },
  zones: {
    byPage: (pageId: number) => ['zones', pageId] as const,
  },
  acceptableContainers: {
    byTarget: (pageId: number, zone: string, elementType: string) =>
      ['acceptableContainers', pageId, zone, elementType] as const,
  },
} as const;
