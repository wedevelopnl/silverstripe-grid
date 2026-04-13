import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { EnrichedSectionNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { useReadonly } from '@/hooks/ReadonlyContext';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { buildBlockClasses } from '@/utils/blockClasses';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ElementActions from '@/components/ElementActions/ElementActions';
import RowBlock from '@/components/RowBlock/RowBlock';
import AddChildButton from '@/components/AddChildButton/AddChildButton';

interface SectionBlockProps {
  readonly section: EnrichedSectionNode;
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

function EditableSectionBlock({ section }: SectionBlockProps) {
  const status = getElementStatus(section.statusFlags);
  const { isCollapsed, toggle } = section;
  const { activeType } = useDragContext();

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } =
    useSortable({ id: section.sortableId });

  const showDropTarget = isOver && activeType === 'section';

  const rootClasses = buildBlockClasses('section-block', status, {
    collapsed: isCollapsed,
    'drop-target': showDropTarget,
  });

  const style = buildSortableStyle(transform, transition, isDragging);

  return (
    <section ref={setNodeRef} style={style} className={rootClasses} data-testid="section-block">
      <div className="section-block__header" data-testid="section-header">
        <DragHandle listeners={listeners} attributes={attributes} label={`Move ${section.title}`} />
        <CollapseToggle isCollapsed={isCollapsed} onToggle={toggle} label={section.title} />
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
        <SortableContext items={section.childSortableIds} strategy={verticalListSortingStrategy}>
          {section.children !== null && section.children.length > 0 ? (
            <>
              {section.children.map((row) => (
                <RowBlock key={row.id} row={row} />
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
  const status = getElementStatus(section.statusFlags);
  const { isCollapsed, toggle } = section;

  const rootClasses = buildBlockClasses('section-block', status, {
    collapsed: isCollapsed,
  });

  return (
    <section className={rootClasses} data-testid="section-block">
      <div className="section-block__header" data-testid="section-header">
        <CollapseToggle isCollapsed={isCollapsed} onToggle={toggle} label={section.title} />
        <i className={`section-block__icon ${section.blockSchema.icon}`} />
        <h2 className="section-block__title" data-testid="section-title">
          {section.title}
        </h2>
      </div>
      <div className="section-block__body">
        {section.children?.map((row) => (
          <RowBlock key={row.id} row={row} />
        ))}
      </div>
    </section>
  );
}
