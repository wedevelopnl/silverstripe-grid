import { skipToken, useQuery } from '@tanstack/react-query'
import { fetchSharedBlocks } from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
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
