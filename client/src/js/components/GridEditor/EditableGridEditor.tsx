import { DndContext, DragOverlay } from '@dnd-kit/core'
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Fragment } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import DragOverlayContent from '@/components/DragOverlayContent/DragOverlayContent'
import { DragContext } from '@/hooks/useDragAndDrop'
import { useEditorTree } from '@/hooks/useEditorTree'
import { type EditorRoot, rootParentRef } from '@/types/editorRoot'
import GridEditorShell, { resolveGridEditorStatus } from './GridEditorShell'
import { renderRootEntry } from './renderRootEntry'
import { useGridEditorDnd } from './useGridEditorDnd'

interface EditableGridEditorProps {
  readonly root: EditorRoot
}

export default function EditableGridEditor({ root }: EditableGridEditorProps) {
  const isBlockRooted = root.kind === 'sharedBlock'
  const rootId = rootParentRef(root).id

  const { data, error } = useEditorTree(root)
  const { sections, sectionIds, dndContextProps, dragState, dragContextValue } = useGridEditorDnd(
    data,
    root,
  )

  const status = resolveGridEditorStatus(data, error)

  // The sections, with "+ Add section" above the first one, in every gap and
  // after the last one; when there are no sections, a single empty-state add
  // button. Always rendered inside DndContext/SortableContext (sectionIds is
  // [] when empty). Each of those buttons carries its own shared-block caret;
  // the library editor's is suppressed by the parentType it receives, since a
  // block may not contain another block.
  //
  // A block owns exactly one subtree, so the library editor offers the add
  // affordance only while the block is still empty — that is the one moment a
  // root is missing. A page zone, by contrast, takes any number of sections.
  const editableSectionList =
    sections.length > 0 ? (
      <>
        {!isBlockRooted && (
          <AddChildButton parentId={rootId} childType="section" variant="before-first" />
        )}
        {sections.map((entry, index) => (
          <Fragment key={entry.nodeKey}>
            {index > 0 && !isBlockRooted && (
              <AddChildButton
                parentId={rootId}
                childType="section"
                variant="between"
                insertAfterId={sections[index - 1].self.id}
              />
            )}
            {renderRootEntry(entry, { siblings: sections })}
          </Fragment>
        ))}
        {!isBlockRooted && (
          <AddChildButton parentId={rootId} childType="section" variant="append" />
        )}
      </>
    ) : (
      <AddChildButton
        parentId={rootId}
        childType="section"
        variant="empty-state"
        parentType={isBlockRooted ? 'sharedBlock' : undefined}
      />
    )

  return (
    <GridEditorShell root={root} readonly={false} status={status} error={error} sections={sections}>
      {/* Droppable measuring stays on the default WhileDragging strategy:
          during a drag it re-measures on every registry change exactly like
          MeasuringStrategy.Always (dnd-kit only checks the strategy when NOT
          dragging), so Always would only add idle-time re-measures of every
          droppable on each tree change. */}
      <DndContext {...dndContextProps}>
        <DragContext.Provider value={dragContextValue}>
          <SortableContext items={sectionIds} strategy={verticalListSortingStrategy}>
            {editableSectionList}
          </SortableContext>
        </DragContext.Provider>
        <DragOverlay>
          {dragState !== null && (
            <DragOverlayContent node={dragState.activeNode} type={dragState.activeType} />
          )}
        </DragOverlay>
      </DndContext>
    </GridEditorShell>
  )
}
