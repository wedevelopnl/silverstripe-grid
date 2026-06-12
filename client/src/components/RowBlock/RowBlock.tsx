import { horizontalListSortingStrategy, SortableContext, useSortable } from '@dnd-kit/sortable'
import { memo, useCallback, useMemo } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle'
import ColumnBlock from '@/components/ColumnBlock/ColumnBlock'
import ColumnInsertButton from '@/components/ColumnInsertButton/ColumnInsertButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import { useReadonly } from '@/hooks/ReadonlyContext'
import { useCollapse } from '@/hooks/useCollapseState'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { t } from '@/i18n'
import type { RowNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { getColumnCount, getOffsetStrategy } from '@/utils/gridAdapter'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'

interface RowBlockProps {
  readonly row: RowNode
}

/**
 * Row block dispatcher: picks the editable or readonly variant based on
 * the `ReadonlyContext`. See `SectionBlock` for the rationale — readonly
 * variants never call `useSortable`, so a readonly grid tree doesn't
 * need a `DndContext` ancestor.
 */
const RowBlock = memo(function RowBlock({ row }: RowBlockProps) {
  const readonly = useReadonly()
  return readonly ? <ReadonlyRowBlock row={row} /> : <EditableRowBlock row={row} />
})

export default RowBlock

/**
 * Build a stable per-column lookup for the "+ insert column" gutter between
 * adjacent columns. Recreated only when the children array (or row id)
 * changes — so the `insertBefore` prop passed to memoised `ColumnBlock`s is
 * reference-stable across unrelated re-renders.
 */
function useInsertBeforeByColumnKey(
  row: RowNode,
): ReadonlyMap<NodeKey, { rowId: number; afterColumnId: number }> {
  return useMemo(() => {
    const map = new Map<NodeKey, { rowId: number; afterColumnId: number }>()
    const cols = row.children ?? []
    for (let i = 1; i < cols.length; i++) {
      map.set(cols[i].nodeKey, { rowId: row.self.id, afterColumnId: cols[i - 1].self.id })
    }
    return map
  }, [row.children, row.self.id])
}

function useRowCollapse(row: RowNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse()
  const isCollapsed = isCollapsedFn(row.nodeKey)
  const onToggle = useCallback(() => toggle(row.nodeKey), [toggle, row.nodeKey])
  return { isCollapsed, onToggle }
}

function useChildColumnKeys(row: RowNode): NodeKey[] {
  return useMemo(() => row.children?.map((c) => c.nodeKey) ?? [], [row.children])
}

function EditableRowBlock({ row }: RowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid'
  const status = row.status
  const { isCollapsed, onToggle } = useRowCollapse(row)
  const { activeType, pendingActive } = useDragContext()

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: row.nodeKey })

  const showDropTarget = isOver && activeType === 'row'

  const style = buildSortableStyle(transform, transition, isDragging)

  const childKeys = useChildColumnKeys(row)
  const columns = row.children ?? []
  const hasColumns = columns.length > 0
  const insertBeforeByKey = useInsertBeforeByColumnKey(row)

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="ssgrid-row"
      data-testid="row-block"
      data-status={status}
      data-collapsed={isCollapsed ? '' : undefined}
      data-drop-target={showDropTarget ? '' : undefined}
    >
      <div className="ssgrid-row__header" data-testid="row-header">
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.RowBlock.MOVE_LABEL', 'Move {title}', { title: row.title })}
        />
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={row.title} />
        <h3 className="ssgrid-row__title" data-testid="row-title">
          {row.editLink !== null ? (
            <a href={row.editLink} data-testid="row-edit-link">
              {row.title}
            </a>
          ) : (
            row.title
          )}
        </h3>
        {status === 'modified' && (
          <span
            className="ssgrid-modified-dot"
            data-testid="row-modified-indicator"
            aria-label={t('WeDevelopGrid.RowBlock.MODIFIED_LABEL', 'Has unpublished changes')}
            role="img"
          />
        )}
        {row.children !== null && row.children.length > 0 && (
          <span className="ssgrid-row__meta" data-testid="row-column-count">
            {t('WeDevelopGrid.RowBlock.COLUMN_COUNT', '{count} columns', {
              count: row.children.length,
            })}
          </span>
        )}
        <ElementActions node={row} collapse={{ isCollapsed, onToggle, label: row.title }} />
      </div>
      <div className="ssgrid-row__columns-area" data-testid="row-block-columns-area">
        {hasColumns && <ColumnInsertButton rowId={row.self.id} placement="start" />}
        <div
          className="ssgrid-row__columns"
          data-testid="row-block-columns"
          data-layout-mode={layoutMode}
          style={
            layoutMode === 'grid'
              ? ({ '--grid-columns': String(getColumnCount()) } as React.CSSProperties)
              : undefined
          }
        >
          <SortableContext
            items={childKeys}
            strategy={pendingActive ? noopSortingStrategy : horizontalListSortingStrategy}
          >
            {hasColumns ? (
              columns.map((column) => (
                <ColumnBlock
                  key={column.nodeKey}
                  column={column}
                  insertBefore={insertBeforeByKey.get(column.nodeKey)}
                />
              ))
            ) : (
              <AddChildButton
                parentId={row.self.id}
                childType="column"
                childLabel="Column"
                variant="empty-state"
              />
            )}
          </SortableContext>
        </div>
        {hasColumns && (
          <ColumnInsertButton
            rowId={row.self.id}
            placement="end"
            afterColumnId={columns[columns.length - 1].self.id}
          />
        )}
      </div>
    </div>
  )
}

function ReadonlyRowBlock({ row }: RowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid'
  const status = row.status
  const { isCollapsed, onToggle } = useRowCollapse(row)

  return (
    <div
      className="ssgrid-row"
      data-testid="row-block"
      data-status={status}
      data-collapsed={isCollapsed ? '' : undefined}
    >
      <div className="ssgrid-row__header" data-testid="row-header">
        <div className="ssgrid-row__header-title">
          <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={row.title} />
          <h3 className="ssgrid-row__title" data-testid="row-title">
            {row.title}
          </h3>
          {status === 'modified' && (
            <span
              className="ssgrid-modified-dot"
              data-testid="row-modified-indicator"
              aria-label={t('WeDevelopGrid.RowBlock.MODIFIED_LABEL', 'Has unpublished changes')}
              role="img"
            />
          )}
          {row.children !== null && row.children.length > 0 && (
            <span className="ssgrid-row__meta" data-testid="row-column-count">
              {t('WeDevelopGrid.RowBlock.COLUMN_COUNT', '{count} columns', {
                count: row.children.length,
              })}
            </span>
          )}
        </div>
      </div>
      <div
        className="ssgrid-row__columns"
        data-testid="row-block-columns"
        data-layout-mode={layoutMode}
        style={
          layoutMode === 'grid'
            ? ({ '--grid-columns': String(getColumnCount()) } as React.CSSProperties)
            : undefined
        }
      >
        {row.children?.map((column) => (
          <ColumnBlock key={column.nodeKey} column={column} />
        ))}
      </div>
    </div>
  )
}
