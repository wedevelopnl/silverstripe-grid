import { useCallback, useMemo } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, horizontalListSortingStrategy } from '@dnd-kit/sortable';
import type { RowNode } from '@/types/elements';
import type { NodeKey } from '@/types/identity';
import type { ElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useReadonly } from '@/hooks/ReadonlyContext';
import { useCollapse } from '@/hooks/useCollapseState';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { createBlockClasses } from '@/utils/blockClasses';
import { getOffsetStrategy, getColumnCount } from '@/utils/gridAdapter';
import { t } from '@/i18n';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ElementActions from '@/components/ElementActions/ElementActions';
import ColumnBlock from '@/components/ColumnBlock/ColumnBlock';
import AddChildButton from '@/components/AddChildButton/AddChildButton';

const buildClasses = createBlockClasses<ElementStatus | 'collapsed' | 'drop-target'>('row-block');

interface RowBlockProps {
  readonly row: RowNode;
}

/**
 * Row block dispatcher: picks the editable or readonly variant based on
 * the `ReadonlyContext`. See `SectionBlock` for the rationale — readonly
 * variants never call `useSortable`, so a readonly grid tree doesn't
 * need a `DndContext` ancestor.
 */
export default function RowBlock({ row }: RowBlockProps) {
  const readonly = useReadonly();
  return readonly ? <ReadonlyRowBlock row={row} /> : <EditableRowBlock row={row} />;
}

function useRowCollapse(row: RowNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse();
  const isCollapsed = isCollapsedFn(row.nodeKey);
  const onToggle = useCallback(() => toggle(row.nodeKey), [toggle, row.nodeKey]);
  return { isCollapsed, onToggle };
}

function useChildColumnKeys(row: RowNode): NodeKey[] {
  return useMemo(() => row.children?.map((c) => c.nodeKey) ?? [], [row.children]);
}

function EditableRowBlock({ row }: RowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid';
  const status = row.status;
  const { isCollapsed, onToggle } = useRowCollapse(row);
  const { activeType } = useDragContext();

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: row.nodeKey });

  const showDropTarget = isOver && activeType === 'row';

  const rootClasses = buildClasses(
    status,
    isCollapsed && 'collapsed',
    showDropTarget && 'drop-target',
  );

  const style = buildSortableStyle(transform, transition, isDragging);

  const childKeys = useChildColumnKeys(row);

  return (
    <div ref={setNodeRef} style={style} className={rootClasses} data-testid="row-block">
      <div className="row-block__header" data-testid="row-header">
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.RowBlock.MOVE_LABEL', 'Move {title}', { title: row.title })}
        />
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={row.title} />
        <i className={`row-block__icon ${row.blockSchema.icon}`} />
        <h3 className="row-block__title" data-testid="row-title">
          {row.editLink !== null ? (
            <a href={row.editLink} data-testid="row-edit-link">
              {row.title}
            </a>
          ) : (
            row.title
          )}
        </h3>
        <ElementActions node={row} />
      </div>
      <div
        className={`row-block__columns row-block__columns--${layoutMode}`}
        style={
          layoutMode === 'grid'
            ? ({ '--grid-columns': String(getColumnCount()) } as React.CSSProperties)
            : undefined
        }
      >
        <SortableContext items={childKeys} strategy={horizontalListSortingStrategy}>
          {row.children !== null && row.children.length > 0 ? (
            <>
              {row.children.map((column) => (
                <ColumnBlock key={column.nodeKey} column={column} />
              ))}
              <AddChildButton
                parentId={row.id}
                childType="column"
                childLabel="Column"
                variant="append"
              />
            </>
          ) : (
            <AddChildButton
              parentId={row.id}
              childType="column"
              childLabel="Column"
              variant="empty-state"
            />
          )}
        </SortableContext>
      </div>
    </div>
  );
}

function ReadonlyRowBlock({ row }: RowBlockProps) {
  const layoutMode = getOffsetStrategy() === 'margin' ? 'flex' : 'grid';
  const status = row.status;
  const { isCollapsed, onToggle } = useRowCollapse(row);

  const rootClasses = buildClasses(status, isCollapsed && 'collapsed');

  return (
    <div className={rootClasses} data-testid="row-block">
      <div className="row-block__header" data-testid="row-header">
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={row.title} />
        <i className={`row-block__icon ${row.blockSchema.icon}`} />
        <h3 className="row-block__title" data-testid="row-title">
          {row.title}
        </h3>
      </div>
      <div
        className={`row-block__columns row-block__columns--${layoutMode}`}
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
  );
}
