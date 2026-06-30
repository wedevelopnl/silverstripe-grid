import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Fragment, memo, useMemo } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import EditableRowBlock from '@/components/RowBlock/EditableRowBlock'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { t } from '@/i18n'
import type { SectionNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'
import SectionChrome from './SectionChrome'

interface EditableSectionBlockProps {
  readonly section: SectionNode
}

function useChildSortableKeys(section: SectionNode): NodeKey[] {
  // biome-ignore lint/suspicious/noUnnecessaryConditions: section.children is `RowNode[] | null`; the ?? [] fallback is required — dropping it fails typecheck.
  return useMemo(() => section.children?.map((r) => r.nodeKey) ?? [], [section.children])
}

const EditableSectionBlock = memo(function EditableSectionBlockComponent({
  section,
}: EditableSectionBlockProps) {
  const status = section.status
  const { isCollapsed, onToggle } = useElementCollapse(section.nodeKey)
  const { activeType, pendingActive } = useDragContext()

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: section.nodeKey })

  const showDropTarget = isOver && activeType === 'section'

  const style = buildSortableStyle(transform, transition, isDragging)

  const childKeys = useChildSortableKeys(section)
  const rows = section.children ?? []
  const hasRows = rows.length > 0

  return (
    <SectionChrome
      status={status}
      title={section.title}
      titleHref={section.editLink ?? undefined}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      dropTarget={showDropTarget}
      setNodeRef={setNodeRef}
      style={style}
      leading={
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.SectionBlock.MOVE_LABEL', 'Move {title}', {
            title: section.title,
          })}
        />
      }
      trailing={
        <ElementActions node={section} collapse={{ isCollapsed, onToggle, label: section.title }} />
      }
    >
      <SortableContext
        items={childKeys}
        strategy={pendingActive ? noopSortingStrategy : verticalListSortingStrategy}
      >
        {hasRows ? (
          <>
            {rows.map((row, index) => (
              <Fragment key={row.nodeKey}>
                {index > 0 && (
                  <AddChildButton
                    parentId={section.self.id}
                    childType="row"
                    childLabel="Row"
                    variant="between"
                    insertAfterId={rows[index - 1].self.id}
                  />
                )}
                <EditableRowBlock row={row} />
              </Fragment>
            ))}
            <AddChildButton
              parentId={section.self.id}
              childType="row"
              childLabel="Row"
              variant="append"
            />
          </>
        ) : (
          <AddChildButton
            parentId={section.self.id}
            childType="row"
            childLabel="Row"
            variant="empty-state"
          />
        )}
      </SortableContext>
    </SectionChrome>
  )
})

export default EditableSectionBlock
