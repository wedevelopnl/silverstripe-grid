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
  sharedBlocks: {
    /** Invalidated by every shared-block mutation: usage counts and statuses shift together. */
    all: () => ['sharedBlocks'] as const,
    list: (parentType: string) => ['sharedBlocks', 'list', parentType] as const,
    tree: (blockId: number) => ['sharedBlocks', 'tree', blockId] as const,
    usage: (blockId: number) => ['sharedBlocks', 'usage', blockId] as const,
  },
} as const

/** Which host the grid editor is running in. */
export type GridEditorRootType = 'page' | 'sharedBlock'

/**
 * The cache entry the editor actually renders.
 *
 * The library editor is keyed by a BLOCK id and reads `sharedBlocks.tree`,
 * while a page zone reads `elementTree.byPage`. Any mutation that snapshots,
 * optimistically writes or invalidates the tree must go through this — writing
 * to `elementTree` while the library editor renders `sharedBlocks.tree` puts
 * the optimistic update in an entry nothing displays, so the drag snaps back
 * and the stale order survives until a reload.
 */
export function editorTreeQueryKey(rootType: GridEditorRootType, id: number, zone: string) {
  return rootType === 'sharedBlock'
    ? queryKeys.sharedBlocks.tree(id)
    : queryKeys.elementTree.byPage(id, zone)
}
