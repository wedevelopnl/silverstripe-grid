import { useCallback } from 'react';
import type { KeyboardEvent } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import type { EnrichedSimpleElementNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { buildSortableStyle } from '@/utils/sortableStyles';
import DragHandle from '@/components/DragHandle/DragHandle';
import ElementActions from '@/components/ElementActions/ElementActions';

interface ElementCardProps {
  readonly element: EnrichedSimpleElementNode;
}

/**
 * Compact read-only card showing an element's type, title, content preview,
 * and publication state via a colored left border.
 */
export default function ElementCard({ element }: ElementCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: element.sortableId });
  const status = getElementStatus(element.statusFlags);
  const content = element.blockSchema.summary;
  const editLink = element.editLink;

  const style = buildSortableStyle(transform, transition, isDragging);

  const navigateToEdit = useCallback(() => {
    if (editLink !== null && editLink.startsWith('/')) {
      window.location.href = editLink;
    }
  }, [editLink]);

  const handleKeyDown = useCallback((event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key === 'Enter') {
      navigateToEdit();
    }
  }, [navigateToEdit]);

  const cardClasses = [
    'element-card',
    `element-card--${status}`,
    ...(editLink !== null ? ['element-card--clickable'] : []),
  ].join(' ');

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={cardClasses}
      data-testid="element-card"
      onClick={navigateToEdit}
      onKeyDown={editLink !== null ? handleKeyDown : undefined}
      role={editLink !== null ? 'link' : undefined}
      tabIndex={editLink !== null ? 0 : undefined}
    >
      <div className="element-card__header">
        <DragHandle listeners={listeners} attributes={attributes} label={`Move ${element.title}`} />
        <i className={`element-card__icon ${element.blockSchema.icon}`} />
        <h4 className="element-card__title" data-testid="element-card-title">{element.title}</h4>
        <ElementActions node={element} />
      </div>
      <div className={`element-card__content${content === '' ? ' element-card__content--empty' : ''}`}>
        {content || 'No preview available'}
      </div>
    </div>
  );
}
