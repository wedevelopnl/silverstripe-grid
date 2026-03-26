import { useMutation, useQueryClient } from '@tanstack/react-query';
import {
  createElement,
  createContentElement,
  archiveElement,
  duplicateElement,
  duplicateToElement,
  publishElement,
  reorderElement,
  unpublishElement,
  updateGridSettings,
  resetGridSettingsOverrides,
} from '@/api/endpoints';
import type { CreateElementParams, CreateContentElementParams, DuplicateToParams, ReorderElementParams, UpdateGridSettingsParams, ResetGridSettingsOverridesParams } from '@/api/endpoints';
import type { ApiError } from '@/api/errors';
import type { ElementTreeResponse, TreeApiResponse } from '@/types/elements';
import { applyReorder } from '@/utils/applyReorder';
import { refreshPreview } from '@/utils/refreshPreview';
import { showToast } from '@/utils/toast';
import { queryKeys } from './queryKeys';

function useInvalidateOnSuccess(pageId: number, zone: string) {
  const queryClient = useQueryClient();

  return {
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: queryKeys.elementTree.byPage(pageId, zone),
      });
      refreshPreview();
    },
  };
}

export function useCreateElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, CreateElementParams>({
    mutationFn: createElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useCreateContentElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, CreateContentElementParams>({
    mutationFn: createContentElement,
    ...useInvalidateOnSuccess(pageId, zone),
    onError: (error) => {
      showToast(error.message);
    },
  });
}

export function usePublishElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, number>({
    mutationFn: publishElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useUnpublishElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, number>({
    mutationFn: unpublishElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useArchiveElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, number>({
    mutationFn: archiveElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useDuplicateElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, number>({
    mutationFn: duplicateElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useDuplicateToElement(pageId: number, zone: string) {
  return useMutation<void, ApiError, DuplicateToParams>({
    mutationFn: duplicateToElement,
    ...useInvalidateOnSuccess(pageId, zone),
  });
}

export function useUpdateGridSettings(pageId: number, zone: string) {
  return useMutation<void, ApiError, UpdateGridSettingsParams>({
    mutationFn: updateGridSettings,
    ...useInvalidateOnSuccess(pageId, zone),
    onError: (error) => {
      showToast(error.message);
    },
  });
}

export function useResetGridSettingsOverrides(pageId: number, zone: string) {
  return useMutation<void, ApiError, ResetGridSettingsOverridesParams>({
    mutationFn: resetGridSettingsOverrides,
    ...useInvalidateOnSuccess(pageId, zone),
    onError: (error) => {
      showToast(error.message);
    },
  });
}

interface ReorderMutationVariables {
  params: ReorderElementParams;
  tree: ElementTreeResponse;
  clearPendingTree?: () => void;
}

export function useReorderElement(pageId: number, zone: string) {
  const queryClient = useQueryClient();
  const queryKey = queryKeys.elementTree.byPage(pageId, zone);

  return useMutation<void, ApiError, ReorderMutationVariables, TreeApiResponse | undefined>({
    mutationFn: ({ params }) => reorderElement(params),
    onMutate: async ({ params, tree, clearPendingTree }) => {
      await queryClient.cancelQueries({ queryKey });

      const snapshot = queryClient.getQueryData<TreeApiResponse>(queryKey);

      const optimistic = applyReorder(
        tree,
        params.elementID,
        params.targetParentId,
        params.afterElementID,
      );

      queryClient.setQueryData<TreeApiResponse>(queryKey, {
        tree: optimistic,
        overrideCounts: snapshot?.overrideCounts ?? {},
      });

      // Clear pending tree after optimistic data is in the cache,
      // preventing a 1-frame snap-back to the original tree.
      clearPendingTree?.();

      return snapshot;
    },
    onError: (error, { clearPendingTree }, snapshot) => {
      // Safety net: clear pending tree if onMutate threw before reaching
      // the clearPendingTree call above.
      clearPendingTree?.();

      if (snapshot !== undefined) {
        queryClient.setQueryData<TreeApiResponse>(queryKey, snapshot);
      }
      showToast(error.message);
    },
    onSettled: async () => {
      await queryClient.invalidateQueries({ queryKey });
      refreshPreview();
    },
  });
}
