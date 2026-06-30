import { DndContext, DragOverlay, MeasuringStrategy } from '@dnd-kit/core'
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Fragment } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import DragOverlayContent from '@/components/DragOverlayContent/DragOverlayContent'
import EditableSectionBlock from '@/components/SectionBlock/EditableSectionBlock'
import { DragContext } from '@/hooks/useDragAndDrop'
import { useElementTree } from '@/hooks/useElementTree'
import GridEditorShell, { resolveGridEditorStatus } from './GridEditorShell'
import { useGridEditorDnd } from './useGridEditorDnd'

interface EditableGridEditorProps {
  readonly pageId: number
  readonly zone: string
}

export default function EditableGridEditor({ pageId, zone }: EditableGridEditorProps) {
  const { data, error } = useElementTree(pageId, zone)
  const { sections, sectionIds, dndContextProps, dragState, dragContextValue } = useGridEditorDnd(
    data,
    pageId,
    zone,
  )

  const status = resolveGridEditorStatus(data, error)

  // The sections, with "+ Add section" in every gap and after the last one;
  // when there are no sections, a single empty-state add button. Always
  // rendered inside DndContext/SortableContext (sectionIds is [] when empty).
  const editableSectionList =
    sections.length > 0 ? (
      <>
        {sections.map((section, index) => (
          <Fragment key={section.nodeKey}>
            {index > 0 && (
              <AddChildButton
                parentId={pageId}
                childType="section"
                childLabel="Section"
                variant="between"
                insertAfterId={sections[index - 1].self.id}
              />
            )}
            <EditableSectionBlock section={section} />
          </Fragment>
        ))}
        <AddChildButton
          parentId={pageId}
          childType="section"
          childLabel="Section"
          variant="append"
        />
      </>
    ) : (
      <AddChildButton
        parentId={pageId}
        childType="section"
        childLabel="Section"
        variant="empty-state"
      />
    )

  return (
    <GridEditorShell
      pageId={pageId}
      zone={zone}
      readonly={false}
      status={status}
      error={error}
      sections={sections}
    >
      <DndContext
        {...dndContextProps}
        measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
      >
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
