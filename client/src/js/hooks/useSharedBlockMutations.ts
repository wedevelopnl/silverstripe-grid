import { useMutation } from '@tanstack/react-query'
import type { CreateSharedBlockParams, PlaceSharedBlockParams } from '@/api/endpoints'
import {
  convertToSharedBlock,
  createSharedBlock,
  detachSharedBlock,
  placeSharedBlock,
  setSharedBlockPublished,
} from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
import type { NodeRef } from '@/types/identity'
import type { SharedBlockCreated } from '@/types/sharedBlocks'
import { showToast } from '@/utils/toast'
import { useStandardMutationOptions } from './useElementMutations'

// These share `useStandardMutationOptions` with the element mutations rather
// than keeping a copy: the invalidation set is identical — the page tree, the
// acceptable-container map and the whole `sharedBlocks` key, which every usage
// count and status chip reads — and two copies drift the moment one is edited.

/**
 * Creating a block from the library listing invalidates nothing: there is no
 * editor tree on that screen, and the caller navigates to the new block's form
 * on success, which reloads everything anyway. It carries its own onError for
 * the same reason — `useStandardMutationOptions` needs a page and a zone.
 */
export function useCreateSharedBlock() {
  return useMutation<SharedBlockCreated, ApiError, CreateSharedBlockParams>({
    mutationFn: createSharedBlock,
    onError: (error) => showToast(error.message),
  })
}

export function usePlaceSharedBlock(pageId: number, zone: string) {
  return useMutation<void, ApiError, PlaceSharedBlockParams>({
    mutationFn: placeSharedBlock,
    ...useStandardMutationOptions(pageId, zone),
  })
}

export function useDetachSharedBlock(pageId: number, zone: string) {
  return useMutation<void, ApiError, { element: NodeRef }>({
    mutationFn: detachSharedBlock,
    ...useStandardMutationOptions(pageId, zone),
  })
}

export function useSetSharedBlockPublished(pageId: number, zone: string) {
  return useMutation<void, ApiError, { blockId: number; published: boolean }>({
    mutationFn: setSharedBlockPublished,
    ...useStandardMutationOptions(pageId, zone),
  })
}

export function useConvertToSharedBlock(pageId: number, zone: string) {
  return useMutation<{ blockId: number }, ApiError, { element: NodeRef; title?: string }>({
    mutationFn: convertToSharedBlock,
    ...useStandardMutationOptions(pageId, zone),
  })
}
