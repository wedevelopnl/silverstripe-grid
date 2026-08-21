import { skipToken, useQuery } from '@tanstack/react-query'
import { fetchSharedBlocks, fetchSharedBlockTree } from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
import type { TreeApiResponse } from '@/types/elements'
import type { SharedBlockListEntry } from '@/types/sharedBlocks'
import type { SharedBlockParentType } from '@/types/sharedBlocks'
import { queryKeys } from './queryKeys'

/**
 * Blocks placeable under `parentType`, or nothing while no parent is chosen.
 *
 * `skipToken` rather than `enabled`: TanStack does not narrow a captured value
 * inside `queryFn` from an `enabled` flag, and this codebase forbids non-null
 * assertions in production code.
 */
export function useSharedBlocks(parentType: SharedBlockParentType | null) {
  return useQuery<SharedBlockListEntry[], ApiError>({
    queryKey: queryKeys.sharedBlocks.list(parentType ?? 'none'),
    queryFn: parentType !== null ? () => fetchSharedBlocks(parentType) : skipToken,
  })
}

/** The library editor's tree. Disabled when the editor is rooted at a page. */
export function useSharedBlockTree(blockId: number | null) {
  return useQuery<TreeApiResponse, ApiError>({
    queryKey: queryKeys.sharedBlocks.tree(blockId ?? 0),
    queryFn: blockId !== null ? () => fetchSharedBlockTree(blockId) : skipToken,
  })
}
