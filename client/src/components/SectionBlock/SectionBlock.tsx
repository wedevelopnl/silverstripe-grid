import { useCallback, useMemo } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { SectionNode } from '@/types/elements';
import type { NodeKey } from '@/types/identity';
import type { ElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useReadonly } from '@/hooks/ReadonlyContext';
import { useCollapse } from '@/hooks/useCollapseState';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { createBlockClasses } from '@/utils/blockClasses';
import { t } from '@/i18n';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ElementActions from '@/components/ElementActions/ElementActions';
import RowBlock from '@/components/RowBlock/RowBlock';
import AddChildButton from '@/components/AddChildButton/AddChildButton';

const buildClasses = createBlockClasses<ElementStatus | 'collapsed' | 'drop-target'>(
  'section-block',
);

interface SectionBlockProps {
  readonly section: SectionNode;
}

/**
 * Section block dispatcher: picks the editable or readonly variant
 * based on the `ReadonlyContext`. The variants are separate components
 * so readonly renders never call `useSortable` or any other drag/mutation
 * hook — meaning the readonly grid tree doesn't need a `DndContext`
 * ancestor at all.
 */
export default function SectionBlock({ section }: SectionBlockProps) {
  const readonly = useReadonly();
  return readonly ? (
    <ReadonlySectionBlock section={section} />
  ) : (
    <EditableSectionBlock section={section} />
  );
}

function useSectionCollapse(section: SectionNode) {
  const { isCollapsed: isCollapsedFn, toggle } = useCollapse();
  const isCollapsed = isCollapsedFn(section.nodeKey);
  const onToggle = useCallback(() => toggle(section.nodeKey), [toggle, section.nodeKey]);
  return { isCollapsed, onToggle };
}

function useChildSortableKeys(section: SectionNode): NodeKey[] {
  return useMemo(() => section.children?.map((r) => r.nodeKey) ?? [], [section.children]);
}

function EditableSectionBlock({ section }: SectionBlockProps) {
  const status = section.status;
  const { isCollapsed, onToggle } = useSectionCollapse(section);
  const { activeType } = useDragContext();

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: section.nodeKey });

  const showDropTarget = isOver && activeType === 'section';

  const rootClasses = buildClasses(
    status,
    isCollapsed && 'collapsed',
    showDropTarget && 'drop-target',
  );

  const style = buildSortableStyle(transform, transition, isDragging);

  const childKeys = useChildSortableKeys(section);

  return (
    <section ref={setNodeRef} style={style} className={rootClasses} data-testid="section-block">
      <div className="section-block__header" data-testid="section-header">
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.SectionBlock.MOVE_LABEL', 'Move {title}', {
            title: section.title,
          })}
        />
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={section.title} />
        <i className={`section-block__icon ${section.blockSchema.icon}`} />
        <h2 className="section-block__title" data-testid="section-title">
          {section.editLink !== null ? (
            <a href={section.editLink} data-testid="section-edit-link">
              {section.title}
            </a>
          ) : (
            section.title
          )}
        </h2>
        <ElementActions node={section} />
      </div>
      <div className="section-block__body">
        <SortableContext items={childKeys} strategy={verticalListSortingStrategy}>
          {section.children !== null && section.children.length > 0 ? (
            <>
              {section.children.map((row) => (
                <RowBlock key={row.nodeKey} row={row} />
              ))}
              <AddChildButton
                parentId={section.id}
                childType="row"
                childLabel="Row"
                variant="append"
              />
            </>
          ) : (
            <AddChildButton
              parentId={section.id}
              childType="row"
              childLabel="Row"
              variant="empty-state"
            />
          )}
        </SortableContext>
      </div>
    </section>
  );
}

function ReadonlySectionBlock({ section }: SectionBlockProps) {
  const status = section.status;
  const { isCollapsed, onToggle } = useSectionCollapse(section);

  const rootClasses = buildClasses(status, isCollapsed && 'collapsed');

  return (
    <section className={rootClasses} data-testid="section-block">
      <div className="section-block__header" data-testid="section-header">
        <CollapseToggle isCollapsed={isCollapsed} onToggle={onToggle} label={section.title} />
        <i className={`section-block__icon ${section.blockSchema.icon}`} />
        <h2 className="section-block__title" data-testid="section-title">
          {section.title}
        </h2>
      </div>
      <div className="section-block__body">
        {section.children?.map((row) => (
          <RowBlock key={row.nodeKey} row={row} />
        ))}
      </div>
    </section>
  );
}
