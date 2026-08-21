import { skipToken, useMutation, useQuery } from '@tanstack/react-query'
import { deleteSharedBlock, fetchSharedBlockUsage } from '@/api/endpoints'
import type { SharedBlockDeleteMode, SharedBlockUsage } from '@/types/sharedBlocks'
import { queryKeys } from './queryKeys'

/**
 * How far a delete of this block would reach. Fetched rather than passed in:
 * the library's edit form is server-rendered once, and a placement added from
 * another screen since then would otherwise go unmentioned in the confirmation.
 */
export function useSharedBlockUsage(blockId: number, enabled: boolean) {
  return useQuery<SharedBlockUsage>({
    queryKey: enabled
      ? queryKeys.sharedBlocks.usage(blockId)
      : (['sharedBlocks', 'usage', 'disabled'] as const),
    queryFn: enabled ? () => fetchSharedBlockUsage(blockId) : skipToken,
  })
}

/**
 * No cache invalidation on success: the block is gone, and the caller leaves
 * for the library index rather than re-rendering anything that could still
 * reference it.
 */
export function useDeleteSharedBlock(blockId: number) {
  return useMutation<void, Error, SharedBlockDeleteMode>({
    mutationFn: (mode) => deleteSharedBlock({ blockId, mode }),
  })
}
