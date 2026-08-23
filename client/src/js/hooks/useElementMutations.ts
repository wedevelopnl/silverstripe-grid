import { useMutation, useQueryClient } from '@tanstack/react-query'
import type {
  CreateContentElementParams,
  CreateElementParams,
  DuplicateToParams,
  ReorderElementParams,
  ResetGridSettingsOverridesParams,
  UpdateGridSettingsParams,
} from '@/api/endpoints'
import {
  archiveElement,
  createContentElement,
  createElement,
  duplicateElement,
  duplicateToElement,
  publishElement,
  reorderElement,
  resetGridSettingsOverrides,
  unpublishElement,
  updateGridSettings,
} from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
import type { EditorRoot } from '@/types/editorRoot'
import type { TreeApiResponse } from '@/types/elements'
import { NodeIdentity, type NodeRef } from '@/types/identity'
import { buildMaps } from '@/hooks/useElementMaps'
import { applyReorder } from '@/utils/applyReorder'
import { refreshPreview } from '@/utils/refreshPreview'
import { showToast } from '@/utils/toast'
import { queryKeys, treeQueryKey } from './queryKeys'

/**
 * Shared mutation defaults: invalidate the tree on success and toast on error.
 *
 * Centralizing onError here prevents drift — every mutation that spreads this helper
 * automatically reports failures to the user. Mutations with custom onError (e.g.
 * useReorderElement's optimistic rollback) should still call showToast explicitly.
 */
export function useStandardMutationOptions(root: EditorRoot) {
  const queryClient = useQueryClient()

  return {
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: treeQueryKey(root),
      })
      queryClient.invalidateQueries({
        queryKey: queryKeys.acceptableContainers.all(),
      })
      // An edit inside a shared block changes its status and, after a detach or
      // convert, its usage — both of which every other page's chip reads.
      queryClient.invalidateQueries({
        queryKey: queryKeys.sharedBlocks.all(),
      })
      refreshPreview()
    },
    onError: (error: ApiError) => {
      showToast(error.message)
    },
  }
}

export function useCreateElement(root: EditorRoot) {
  return useMutation<void, ApiError, CreateElementParams>({
    mutationFn: createElement,
    ...useStandardMutationOptions(root),
  })
}

export function useCreateContentElement(root: EditorRoot) {
  return useMutation<void, ApiError, CreateContentElementParams>({
    mutationFn: createContentElement,
    ...useStandardMutationOptions(root),
  })
}

export function usePublishElement(root: EditorRoot) {
  return useMutation<void, ApiError, NodeRef>({
    mutationFn: publishElement,
    ...useStandardMutationOptions(root),
  })
}

export function useUnpublishElement(root: EditorRoot) {
  return useMutation<void, ApiError, NodeRef>({
    mutationFn: unpublishElement,
    ...useStandardMutationOptions(root),
  })
}

export function useArchiveElement(root: EditorRoot) {
  return useMutation<void, ApiError, NodeRef>({
    mutationFn: archiveElement,
    ...useStandardMutationOptions(root),
  })
}

export function useDuplicateElement(root: EditorRoot) {
  return useMutation<void, ApiError, NodeRef>({
    mutationFn: duplicateElement,
    ...useStandardMutationOptions(root),
  })
}

export function useDuplicateToElement(root: EditorRoot) {
  const queryClient = useQueryClient()
  const standardOptions = useStandardMutationOptions(root)

  return useMutation<void, ApiError, DuplicateToParams>({
    mutationFn: duplicateToElement,
    ...standardOptions,
    // A cross-page/zone duplicate writes into the DESTINATION tree, which the
    // shared onSuccess (source-only) never invalidates — so the duplicate
    // wouldn't appear without a manual refetch. Invalidate the destination too,
    // reading the target from the mutation variables.
    onSuccess: (_data, variables) => {
      standardOptions.onSuccess()
      queryClient.invalidateQueries({
        queryKey: queryKeys.elementTree.byPage(variables.targetPageId, variables.targetZone),
      })
    },
    // Suppress the standard error toast: the DuplicateToDialog presents the error
    // inline (via the mutate-level onError in useDuplicateToAction), so the toast
    // would be a second, redundant presentation of the same failure.
    onError: () => {},
  })
}

export function useUpdateGridSettings(root: EditorRoot) {
  return useMutation<void, ApiError, UpdateGridSettingsParams>({
    mutationFn: updateGridSettings,
    ...useStandardMutationOptions(root),
  })
}

export function useResetGridSettingsOverrides(root: EditorRoot) {
  return useMutation<void, ApiError, ResetGridSettingsOverridesParams>({
    mutationFn: resetGridSettingsOverrides,
    ...useStandardMutationOptions(root),
  })
}

interface ReorderMutationVariables {
  params: ReorderElementParams
  tree: TreeApiResponse
  clearPendingTree?: () => void
}

export function useReorderElement(root: EditorRoot) {
  const queryClient = useQueryClient()
  const queryKey = treeQueryKey(root)

  // onMutate (applyReorder) can throw a plain Error/TypeError, which TanStack
  // routes to onError — so the error channel is `Error | ApiError`, not just
  // ApiError. showToast(error.message) works for both (message is on Error).
  return useMutation<void, Error | ApiError, ReorderMutationVariables, TreeApiResponse | undefined>(
    {
      mutationFn: ({ params }) => reorderElement(params),
      onMutate: async ({ params, tree, clearPendingTree }) => {
        await queryClient.cancelQueries({ queryKey })

        const snapshot = queryClient.getQueryData<TreeApiResponse>(queryKey)

        const elementKey = NodeIdentity.toKey(params.element)
        const parentKey = NodeIdentity.toKey(params.parent)
        const afterKey = params.after === null ? null : NodeIdentity.toKey(params.after)

        const optimistic = applyReorder(tree, buildMaps(tree), elementKey, parentKey, afterKey)

        queryClient.setQueryData<TreeApiResponse>(queryKey, optimistic)

        // Clear pending tree after optimistic data is in the cache,
        // preventing a 1-frame snap-back to the original tree.
        clearPendingTree?.()

        return snapshot
      },
      onError: (error, { clearPendingTree }, snapshot) => {
        // Safety net: clear pending tree if onMutate threw before reaching
        // the clearPendingTree call above.
        clearPendingTree?.()

        if (snapshot !== undefined) {
          queryClient.setQueryData<TreeApiResponse>(queryKey, snapshot)
        }
        showToast(error.message)
      },
      // Invalidate only on success: on error we've already restored the
      // snapshot locally, and a refetch would cause a second tree swap
      // (flicker) after the rollback has settled visually.
      onSuccess: async () => {
        await queryClient.invalidateQueries({ queryKey })
        // Reordering inside a block leaves it with unpublished changes, which
        // every consuming page's chip reads.
        await queryClient.invalidateQueries({ queryKey: queryKeys.sharedBlocks.all() })
        refreshPreview()
      },
    },
  )
}
