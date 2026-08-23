import type { EditorRoot } from '@/types/editorRoot'

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
  },
} as const

/**
 * The cache entry the editor renders, for whichever record it is rooted at.
 *
 * The single key builder for editor trees: anything that snapshots,
 * optimistically writes or invalidates the tree goes through this. Writing to
 * `elementTree` while the library editor renders `sharedBlocks.tree` puts the
 * update in an entry nothing displays — the drag snaps back and the stale
 * order survives until a reload.
 */
export function treeQueryKey(root: EditorRoot) {
  return root.kind === 'sharedBlock'
    ? queryKeys.sharedBlocks.tree(root.blockId)
    : queryKeys.elementTree.byPage(root.pageId, root.zone, root.version)
}
