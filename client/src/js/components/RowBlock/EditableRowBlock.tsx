import { horizontalListSortingStrategy, SortableContext, useSortable } from '@dnd-kit/sortable'
import { memo, useId, useMemo } from 'react'
import AddChildButton from '@/components/AddChildButton/AddChildButton'
import EditableColumnBlock from '@/components/ColumnBlock/EditableColumnBlock'
import { usePlacement } from '@/components/SharedBlockFrame/PlacementContext'
import SharedChild from '@/components/SharedBlockFrame/SharedChild'
import ColumnInsertButton from '@/components/ColumnInsertButton/ColumnInsertButton'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { useElementCollapse } from '@/hooks/useElementCollapse'
import { t } from '@/i18n'
import { isSharedBlockReferenceNode, isSharedBlockRootNode, type RowNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { getColumnCount, getOffsetStrategy } from '@/utils/gridAdapter'
import { buildSortableStyle, noopSortingStrategy } from '@/utils/sortableStyles'
import { hasUnpublishedDescendant } from '@/utils/publishStatus'
import RowChrome from './RowChrome'

interface EditableRowBlockProps {
  readonly row: RowNode
}

/**
 * Build a stable per-column lookup for the "+ insert column" gutter between
 * adjacent columns. Recreated only when the children array (or row id)
 * changes — so the `insertBefore` prop passed to memoised `EditableColumnBlock`s
 * is reference-stable across unrelated re-renders.
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
function useChildColumnKeys(row: RowNode): NodeKey[] {
  return useMemo(
    () =>
      row.children?.filter((child) => !isSharedBlockReferenceNode(child)).map((c) => c.nodeKey) ??
      [],
    [row.children],
  )
}

const EditableRowBlock = memo(function EditableRowBlockComponent({ row }: EditableRowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid'
  const status = row.status
  const { isCollapsed, onToggle } = useElementCollapse(row.nodeKey)
  const { activeType, pendingActive } = useDragContext()

  // Inside a placed shared block the content is read-only on the page: edits
  // belong in the library, and the frame's own bar carries the block's actions.
  const insideShared = usePlacement() !== null

  // A block's root has nowhere to be dragged to — see isSharedBlockRootNode.
  const isBlockRoot = isSharedBlockRootNode(row)

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: row.nodeKey })

  const showDropTarget = isOver && activeType === 'row'

  const style = buildSortableStyle(transform, transition, isDragging)

  const childKeys = useChildColumnKeys(row)
  const columns = row.children ?? []
  const hasColumns = columns.length > 0
  const insertBeforeByKey = useInsertBeforeByColumnKey(row)
  const bodyId = useId()

  const trailing = insideShared ? undefined : (
    <ElementActions node={row} collapse={{ isCollapsed, onToggle, label: row.title }} />
  )

  return (
    <RowChrome
      status={status}
      hasUnpublishedDescendant={hasUnpublishedDescendant(row)}
      title={row.title}
      titleHref={insideShared ? undefined : (row.editLink ?? undefined)}
      isCollapsed={isCollapsed}
      onToggle={onToggle}
      columnCount={row.children?.length ?? 0}
      bodyId={bodyId}
      dropTarget={showDropTarget}
      setNodeRef={setNodeRef}
      style={style}
      leading={
        insideShared || isBlockRoot ? undefined : (
          <DragHandle
            listeners={listeners}
            attributes={attributes}
            label={t('WeDevelopGrid.RowBlock.MOVE_LABEL', 'Move {title}', { title: row.title })}
          />
        )
      }
      trailing={trailing}
    >
      <div id={bodyId} className="ssgrid-row-columns-area" data-testid="row-block-columns-area">
        {hasColumns && !insideShared && (
          <ColumnInsertButton rowId={row.self.id} placement="start" />
        )}
        <div
          className="ssgrid-row-columns"
          data-testid="row-block-columns"
          data-dnd-container=""
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
            {hasColumns
              ? columns.map((column) => (
                  <SharedChild
                    key={column.nodeKey}
                    child={column}
                    siblings={columns}
                    render={(node) => (
                      <EditableColumnBlock
                        column={node}
                        insertBefore={
                          insideShared ? undefined : insertBeforeByKey.get(column.nodeKey)
                        }
                      />
                    )}
                  />
                ))
              : !insideShared && (
                  <AddChildButton parentId={row.self.id} childType="column" variant="empty-state" />
                )}
          </SortableContext>
        </div>
        {hasColumns && !insideShared && (
          <ColumnInsertButton
            rowId={row.self.id}
            placement="end"
            afterColumnId={columns[columns.length - 1].self.id}
          />
        )}
      </div>
    </RowChrome>
  )
})

export default EditableRowBlock
