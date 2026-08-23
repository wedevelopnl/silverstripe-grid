import { useCallback, useMemo } from 'react'
import type { DragContextValue, UseDragAndDropReturn } from '@/hooks/useDragAndDrop'
import { useDragAndDrop } from '@/hooks/useDragAndDrop'
import { useReorderElement } from '@/hooks/useElementMutations'
import { type EditorRoot, rootParentRef } from '@/types/editorRoot'
import type { ElementNode, TreeApiResponse } from '@/types/elements'
import { isSectionNode } from '@/types/elements'
import type { NodeKey, NodeRef } from '@/types/identity'
import { selectSections } from './selectSections'

export interface UseGridEditorDndReturn {
  readonly sections: ElementNode[]
  readonly sectionIds: NodeKey[]
  readonly dndContextProps: UseDragAndDropReturn['dndContextProps']
  readonly dragState: UseDragAndDropReturn['dragState']
  readonly dragContextValue: DragContextValue
}

/**
 * Editable-mode orchestration for the grid editor: wires the reorder mutation,
 * the drag-and-drop hook, the pending-tree-aware section derivation, and the
 * memoised props consumed by DndContext/SortableContext/DragContext.
 */
export function useGridEditorDnd(
  data: TreeApiResponse | undefined,
  root: EditorRoot,
): UseGridEditorDndReturn {
  const reorderMutation = useReorderElement(root)

  // useDragAndDrop puts onReorder in its handleDragEnd useCallback deps. An
  // inline arrow would burn that memoisation on every parent render and
  // re-create dndContextProps → DndContext props. handleDragEnd already
  // invalidates on `tree`, so adding `data` here doesn't widen the bust
  // footprint.
  const onReorder = useCallback(
    (element: NodeRef, parent: NodeRef, after: NodeRef | null, clearPendingTree: () => void) => {
      if (data === undefined) return
      // Serialize reorders: a drag that ends while a previous reorder is still in
      // flight is dropped rather than starting a second overlapping optimistic
      // update. Two concurrent reorders can otherwise roll back to a stale
      // snapshot (the second captures the first's optimistic move) and leave a
      // wrong tree cached. Reorders complete in ~100ms, so the dropped drag is
      // immediately retryable; clear the pending tree so the item snaps back.
      if (reorderMutation.isPending) {
        clearPendingTree()
        return
      }
      reorderMutation.mutate({ params: { element, parent, after }, tree: data, clearPendingTree })
    },
    [data, reorderMutation],
  )

  const { dndContextProps, dragState, pendingTree } = useDragAndDrop({
    tree: data ?? { rootParent: rootParentRef(root), nodes: [] },
    onReorder,
  })

  // Use the pending tree during cross-container drags for visual feedback.
  const effectiveData = pendingTree ?? data

  // Memoise the section derivation: keying on `effectiveData` keeps both
  // `sections` and the derived `sectionIds` (and thus DndContext/SortableContext
  // props) reference-stable across unrelated re-renders.
  const sections = useMemo(() => selectSections(effectiveData), [effectiveData])
  // Only Sections register as sortables. A shared block placement is framed,
  // not dragged (v1): mixed-type sibling dragging at page root is the module's
  // highest-risk surface, so placements move via explicit up/down actions.
  const sectionIds = useMemo(() => sections.filter(isSectionNode).map((s) => s.nodeKey), [sections])

  const dragContextValue = useMemo<DragContextValue>(
    () => ({ activeType: dragState?.activeType ?? null, pendingActive: pendingTree !== null }),
    [dragState?.activeType, pendingTree],
  )

  return { sections, sectionIds, dndContextProps, dragState, dragContextValue }
}
