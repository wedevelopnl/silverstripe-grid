import { useCallback, useMemo } from 'react'
import type { DragContextValue, UseDragAndDropReturn } from '@/hooks/useDragAndDrop'
import { useDragAndDrop } from '@/hooks/useDragAndDrop'
import { useReorderElement } from '@/hooks/useElementMutations'
import type { SectionNode, TreeApiResponse } from '@/types/elements'
import type { NodeKey, NodeRef } from '@/types/identity'
import { selectSections } from './selectSections'

export interface UseGridEditorDndReturn {
  readonly sections: SectionNode[]
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
  pageId: number,
  zone: string,
): UseGridEditorDndReturn {
  const reorderMutation = useReorderElement(pageId, zone)

  // useDragAndDrop puts onReorder in its handleDragEnd useCallback deps. An
  // inline arrow would burn that memoisation on every parent render and
  // re-create dndContextProps → DndContext props. handleDragEnd already
  // invalidates on `tree`, so adding `data` here doesn't widen the bust
  // footprint.
  const onReorder = useCallback(
    (element: NodeRef, parent: NodeRef, after: NodeRef | null, clearPendingTree: () => void) => {
      if (data === undefined) return
      reorderMutation.mutate({ params: { element, parent, after }, tree: data, clearPendingTree })
    },
    [data, reorderMutation],
  )

  const { dndContextProps, dragState, pendingTree } = useDragAndDrop({
    tree: data ?? { rootParent: { type: 'page', id: pageId }, nodes: [] },
    onReorder,
  })

  // Use the pending tree during cross-container drags for visual feedback.
  const effectiveData = pendingTree ?? data

  // Memoise the section derivation: keying on `effectiveData` keeps both
  // `sections` and the derived `sectionIds` (and thus DndContext/SortableContext
  // props) reference-stable across unrelated re-renders.
  const sections = useMemo(() => selectSections(effectiveData), [effectiveData])
  const sectionIds = useMemo(() => sections.map((s) => s.nodeKey), [sections])

  const dragContextValue = useMemo<DragContextValue>(
    () => ({ activeType: dragState?.activeType ?? null, pendingActive: pendingTree !== null }),
    [dragState?.activeType, pendingTree],
  )

  return { sections, sectionIds, dndContextProps, dragState, dragContextValue }
}
