import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Fragment, memo, useMemo } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import EditableRowBlock from '@/components/RowBlock/EditableRowBlock'
import { usePlacement } from '@/components/SharedBlockFrame/PlacementContext'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { t } from '@/i18n'
import { isSharedBlockReferenceNode, type SectionNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'
import SectionChrome from './SectionChrome'

interface EditableSectionBlockProps {
  readonly section: SectionNode
}

/**
 * Sortable ids for this container's children, excluding shared block
 * placements.
 *
 * A placement registers no sortable — `SharedBlockFrame` is deliberately not
 * one, and the sortable rendered inside it carries the block ROOT's nodeKey.
 * Leaving its key in `items` puts a hole in dnd-kit's `getSortedRects`, so
 * `getItemGap` reads no rect on either side of it and the siblings after it
 * shift by the wrong distance during a same-container drag. `useGridEditorDnd`
 * filters the page root for exactly this reason.
 */
function useChildSortableKeys(section: SectionNode): NodeKey[] {
  return useMemo(
    () =>
      section.children
        ?.filter((child) => !isSharedBlockReferenceNode(child))
        .map((r) => r.nodeKey) ?? [],
    [section.children],
  )
}

const EditableSectionBlock = memo(function EditableSectionBlockComponent({
  section,
}: EditableSectionBlockProps) {
  const status = section.status
  const { isCollapsed, onToggle } = useElementCollapse(section.nodeKey)
  const { activeType, pendingActive } = useDragContext()

  // Inside a placed shared block the content is read-only on the page: edits
  // belong in the library, and the frame's own bar carries the block's actions.
  const insideShared = usePlacement() !== null

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: section.nodeKey })

  const showDropTarget = isOver && activeType === 'section'

  const style = buildSortableStyle(transform, transition, isDragging)

  const childKeys = useChildSortableKeys(section)
  const rows = section.children ?? []
  const hasRows = rows.length > 0

  const trailing = insideShared ? undefined : (
    <ElementActions node={section} collapse={{ isCollapsed, onToggle, label: section.title }} />
  )

  return (
    <SectionChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(section)}
      title={section.title}
      titleHref={insideShared ? undefined : (section.editLink ?? undefined)}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      dropTarget={showDropTarget}
      setNodeRef={setNodeRef}
      style={style}
      leading={
        insideShared ? undefined : (
          <DragHandle
            listeners={listeners}
            attributes={attributes}
            label={t('WeDevelopGrid.SectionBlock.MOVE_LABEL', 'Move {title}', {
              title: section.title,
            })}
          />
        )
      }
      trailing={trailing}
    >
      <SortableContext
        items={childKeys}
        strategy={pendingActive ? noopSortingStrategy : verticalListSortingStrategy}
      >
        {hasRows ? (
          <>
            {!insideShared && (
              <AddChildButton parentId={section.self.id} childType="row" variant="before-first" />
            )}
            {rows.map((row, index) => (
              <Fragment key={row.nodeKey}>
                {index > 0 && !insideShared && (
                  <AddChildButton
                    parentId={section.self.id}
                    childType="row"
                    variant="between"
                    insertAfterId={rows[index - 1].self.id}
                  />
                )}
                <SharedChild
                  child={row}
                  siblings={rows}
                  render={(node) => <EditableRowBlock row={node} />}
                />
              </Fragment>
            ))}
            {!insideShared && (
              <AddChildButton parentId={section.self.id} childType="row" variant="append" />
            )}
          </>
        ) : (
          !insideShared && (
            <AddChildButton parentId={section.self.id} childType="row" variant="empty-state" />
          )
        )}
      </SortableContext>
    </SectionChrome>
  )
})

export default EditableSectionBlock
