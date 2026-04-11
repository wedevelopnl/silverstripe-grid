import { useCallback } from 'react';
import type { KeyboardEvent } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import type { EnrichedSimpleElementNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { buildSortableStyle } from '@/utils/sortableStyles';
import { useReadonly } from '@/hooks/ReadonlyContext';
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
  const readonly = useReadonly();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: element.sortableId,
  });
  const status = getElementStatus(element.statusFlags);
  const content = element.blockSchema.summary;
  const editLink = element.editLink;
  const isClickable = !readonly && editLink !== null;

  const style = buildSortableStyle(transform, transition, isDragging);

  const navigateToEdit = useCallback(() => {
    if (editLink?.startsWith('/')) {
      window.location.href = editLink;
    }
  }, [editLink]);

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLDivElement>) => {
      if (event.key === 'Enter') {
        navigateToEdit();
      }
    },
    [navigateToEdit],
  );

  const cardClasses = [
    'element-card',
    `element-card--${status}`,
    ...(isClickable ? ['element-card--clickable'] : []),
  ].join(' ');

  return (
    // biome-ignore lint/a11y/noNoninteractiveElementInteractions: dnd-kit sortable root; link semantics are applied conditionally via role + keyboard handler when editLink exists.
    // biome-ignore lint/a11y/noStaticElementInteractions: same — the root div is a sortable container that conditionally behaves as a link; cannot be restructured as <a> without breaking DnD integration.
    <div
      ref={setNodeRef}
      style={style}
      className={cardClasses}
      data-testid="element-card"
      onClick={isClickable ? navigateToEdit : undefined}
      onKeyDown={isClickable ? handleKeyDown : undefined}
      role={isClickable ? 'link' : undefined}
      tabIndex={isClickable ? 0 : undefined}
    >
      <div className="element-card__header">
        {!readonly && (
          <DragHandle listeners={listeners} attributes={attributes} label={`Move ${element.title}`} />
        )}
        <i className={`element-card__icon ${element.blockSchema.icon}`} />
        <h4 className="element-card__title" data-testid="element-card-title">
          {element.title}
        </h4>
        {!readonly && <ElementActions node={element} />}
      </div>
      <div
        className={`element-card__content${content === '' ? ' element-card__content--empty' : ''}`}
      >
        {content || 'No preview available'}
      </div>
    </div>
  );
}
