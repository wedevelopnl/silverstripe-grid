import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { Fragment, memo, useCallback, useMemo } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import RowBlock from '@/components/RowBlock/RowBlock'
import { useReadonly } from '@/hooks/ReadonlyContext'
import { useCollapse } from '@/hooks/useCollapseState'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { t } from '@/i18n'
import type { SectionNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'

interface SectionBlockProps {
  readonly section: SectionNode
}

/**
 * Section block dispatcher: picks the editable or readonly variant
 * based on the `ReadonlyContext`. The variants are separate components
 * so readonly renders never call `useSortable` or any other drag/mutation
 * hook — meaning the readonly grid tree doesn't need a `DndContext`
 * ancestor at all.
 */
const SectionBlock = memo(function SectionBlock({ section }: SectionBlockProps) {
  const readonly = useReadonly()
  return readonly ? (
    <ReadonlySectionBlock section={section} />
  ) : (
    <EditableSectionBlock section={section} />
  )
})

export default SectionBlock

function useSectionCollapse(section: SectionNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse()
  const isCollapsed = isCollapsedFn(section.nodeKey)
  const onToggle = useCallback(() => toggle(section.nodeKey), [toggle, section.nodeKey])
  return { isCollapsed, onToggle }
}

function useChildSortableKeys(section: SectionNode): NodeKey[] {
  return useMemo(() => section.children?.map((r) => r.nodeKey) ?? [], [section.children])
}

function EditableSectionBlock({ section }: SectionBlockProps) {
  const status = section.status
  const { isCollapsed, onToggle } = useSectionCollapse(section)
  const { activeType, pendingActive } = useDragContext()

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: section.nodeKey })

  const showDropTarget = isOver && activeType === 'section'

  const style = buildSortableStyle(transform, transition, isDragging)

  const childKeys = useChildSortableKeys(section)
  const rows = section.children ?? []
  const hasRows = rows.length > 0

  return (
    <section
      ref={setNodeRef}
      style={style}
      className="ssgrid-section"
      data-testid="section-block"
      data-status={status}
      data-collapsed={isCollapsed ? '' : undefined}
      data-drop-target={showDropTarget ? '' : undefined}
    >
      <div className="ssgrid-section__header" data-testid="section-header">
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.SectionBlock.MOVE_LABEL', 'Move {title}', {
            title: section.title,
          })}
        />
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={section.title} />
        <h2 className="ssgrid-section__title" data-testid="section-title">
          {section.editLink !== null ? (
            <a href={section.editLink} data-testid="section-edit-link">
              {section.title}
            </a>
          ) : (
            section.title
          )}
        </h2>
        {status === 'modified' && (
          <span
            className="ssgrid-modified-dot"
            data-testid="section-modified-indicator"
            aria-label={t('WeDevelopGrid.SectionBlock.MODIFIED_LABEL', 'Has unpublished changes')}
            role="img"
          />
        )}
        <ElementActions node={section} collapse={{ isCollapsed, onToggle, label: section.title }} />
      </div>
      <div className="ssgrid-section__body">
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
                  <RowBlock row={row} />
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
      </div>
    </section>
  )
}

function ReadonlySectionBlock({ section }: SectionBlockProps) {
  const status = section.status
  const { isCollapsed, onToggle } = useSectionCollapse(section)

  return (
    <section
      className="ssgrid-section"
      data-testid="section-block"
      data-status={status}
      data-collapsed={isCollapsed ? '' : undefined}
    >
      <div className="ssgrid-section__header" data-testid="section-header">
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={section.title} />
        <h2 className="ssgrid-section__title" data-testid="section-title">
          {section.title}
        </h2>
        {status === 'modified' && (
          <span
            className="ssgrid-modified-dot"
            data-testid="section-modified-indicator"
            aria-label={t('WeDevelopGrid.SectionBlock.MODIFIED_LABEL', 'Has unpublished changes')}
            role="img"
          />
        )}
      </div>
      <div className="ssgrid-section__body">
        {section.children?.map((row) => (
          <RowBlock key={row.nodeKey} row={row} />
        ))}
      </div>
    </section>
  )
}
