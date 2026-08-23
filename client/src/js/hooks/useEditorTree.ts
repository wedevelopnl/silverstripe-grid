import { queryOptions, skipToken, useQuery } from '@tanstack/react-query'
import { fetchTree } from '@/api/endpoints'
import type { ApiError } from '@/api/errors'
import type { EditorRoot } from '@/types/editorRoot'
import type { TreeApiResponse } from '@/types/elements'
import { countOverrides, type OverrideCounts } from '@/utils/countOverrides'
import { treeQueryKey } from './queryKeys'

function treeQueryOptions(root: EditorRoot | null) {
  // Archived versions are immutable server-side, so a version-specific tree never
  // goes stale: mark it fresh forever so remounting (e.g. stepping through the
  // history viewer) and window refocus reuse the cache instead of refetching.
  const isArchived = root !== null && root.kind === 'page' && root.version !== undefined

  // queryOptions rather than a plain object literal: inferring the literal
  // widens `skipToken`'s unique symbol to `symbol`, which no useQuery overload
  // accepts.
  return queryOptions<TreeApiResponse, ApiError>({
    queryKey: root !== null ? treeQueryKey(root) : ['editorTree', 'disabled'],
    queryFn: root !== null ? () => fetchTree(root) : skipToken,
    // The live (draft) tree gets a modest staleTime instead of the default 0:
    // every mutation invalidates the query explicitly (which overrides staleTime
    // — invalidation sets isInvalidated), so the only refetches this suppresses
    // are the redundant ones from window refocus / remount when nothing changed.
    // CMS editors tab in and out constantly; each of those refetches rebuilds
    // the full tree server-side. 30s keeps cross-editor drift short while
    // eliminating the churn.
    staleTime: isArchived ? Number.POSITIVE_INFINITY : 30_000,
  })
}

/**
 * Fetches and caches the tree the editor renders — a page zone or a shared
 * block, decided by the root it is given. Pass `null` to disable (the host has
 * no root yet, or a readonly host that never reads the draft tree).
 */
export function useEditorTree(root: EditorRoot | null) {
  return useQuery<TreeApiResponse, ApiError>({
    ...treeQueryOptions(root),
  })
}

// Module-scope so the select identity is stable: TanStack re-runs `select`
// whenever data OR the select function changes, and an inline arrow would
// re-walk the tree on every consumer render instead of only on new data.
const selectOverrideCounts = (response: TreeApiResponse): OverrideCounts =>
  countOverrides(response.nodes)

/**
 * Derives per-viewport grid-settings override counts from the cached tree.
 * Shares the same query cache as {@link useEditorTree} — no extra fetch.
 */
export function useViewportOverrideCounts(root: EditorRoot | null): OverrideCounts {
  const { data } = useQuery<TreeApiResponse, ApiError, OverrideCounts>({
    ...treeQueryOptions(root),
    select: selectOverrideCounts,
  })

  return data ?? { total: 0, byViewport: {} }
}
