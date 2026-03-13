import { useSortable } from '@dnd-kit/sortable';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import type { EnrichedSectionNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { useDragContext } from '@/hooks/useDragAndDrop';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { buildBlockClasses } from '@/utils/blockClasses';
import DragHandle from '@/components/DragHandle/DragHandle';
import CollapseToggle from '@/components/CollapseToggle/CollapseToggle';
import ActionsMenu from '@/components/ActionsMenu/ActionsMenu';
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog';
import RowBlock from '@/components/RowBlock/RowBlock';
import AddChildButton from '@/components/AddChildButton/AddChildButton';
import { useArchiveAction } from '@/hooks/useArchiveAction';

interface SectionBlockProps {
  readonly section: EnrichedSectionNode;
}

export default function SectionBlock({ section }: SectionBlockProps) {
  const status = getElementStatus(section.statusFlags);
  const { isCollapsed, toggle } = section;
  const { activeType } = useDragContext();

  const { action: archiveAction, dialog: archiveDialog } = useArchiveAction(section);
  const actions = archiveAction !== null ? [archiveAction] : [];

  const { attributes, listeners, setNodeRef, transform, transition, isDragging, isOver } = useSortable({ id: section.sortableId });

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
          {section.editLink !== null
            ? <a href={section.editLink} data-testid="section-edit-link">{section.title}</a>
            : section.title}
        </h2>
        <ActionsMenu actions={actions} />
      </div>
      {archiveDialog !== null && archiveDialog.isOpen && (
        <ConfirmDialog
          isOpen={archiveDialog.isOpen}
          title={archiveDialog.title}
          message={archiveDialog.message}
          confirmLabel="Archive"
          onConfirm={archiveDialog.onConfirm}
          onCancel={archiveDialog.onCancel}
          destructive
        />
      )}
      <div className="section-block__body">
        <SortableContext items={section.childSortableIds} strategy={verticalListSortingStrategy}>
          {section.children !== null && section.children.length > 0
            ? (
              <>
                {section.children.map((row) => (
                  <RowBlock
                    key={row.id}
                    row={row}
                  />
                ))}
                <AddChildButton
                  parentId={section.id}
                  childType="row"
                  childLabel="Row"
                  variant="append"
                />
              </>
            )
            : (
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
