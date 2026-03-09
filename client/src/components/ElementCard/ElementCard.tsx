import { useCallback } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import type { EnrichedSimpleElementNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { buildSortableStyle } from '@/utils/sortableStyles';
import DragHandle from '@/components/DragHandle/DragHandle';

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
  const label = element.blockSchema.label;
  const content = element.blockSchema.summary;
  const editLink = 'editLink' in element ? (element.editLink as string | null) : null;

  const style = buildSortableStyle(transform, transition, isDragging);

  const handleClick = useCallback(() => {
    if (editLink !== null) {
      window.location.href = editLink;
    }
  }, [editLink]);

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
      onClick={handleClick}
      role={editLink !== null ? 'link' : undefined}
    >
      <div className="element-card__header">
        <DragHandle listeners={listeners} attributes={attributes} label={`Move ${element.title}`} />
        <span className="element-card__type">{label}</span>
        <h4 className="element-card__title" data-testid="element-card-title">{element.title}</h4>
      </div>
      <div className={`element-card__content${content === '' ? ' element-card__content--empty' : ''}`}>
        {content || 'No preview available'}
      </div>
    </div>
  );
}
