import { useCallback } from 'react';
import type { KeyboardEvent } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import type { EnrichedSimpleElementNode } from '@/types/enriched';
import { getElementStatus } from '@/types/status';
import { useReadonly } from '@/hooks/ReadonlyContext';
import { buildSortableStyle } from '@/utils/sortableStyles';
import DragHandle from '@/components/DragHandle/DragHandle';
import ElementActions from '@/components/ElementActions/ElementActions';

interface ElementCardProps {
  readonly element: EnrichedSimpleElementNode;
}

/**
 * Element card dispatcher: picks the editable or readonly variant
 * based on the `ReadonlyContext`. The readonly variant drops
 * `useSortable`, navigation callbacks, and interactive controls —
 * just renders the icon, title, and content preview inside the
 * status-colored border.
 */
export default function ElementCard({ element }: ElementCardProps) {
  const readonly = useReadonly();
  return readonly ? (
    <ReadonlyElementCard element={element} />
  ) : (
    <EditableElementCard element={element} />
  );
}

function EditableElementCard({ element }: ElementCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: element.sortableId,
  });
  const status = getElementStatus(element.statusFlags);
  const content = element.blockSchema.summary;
  const editLink = element.editLink;
  const isClickable = editLink !== null;

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
        <DragHandle listeners={listeners} attributes={attributes} label={`Move ${element.title}`} />
        <i className={`element-card__icon ${element.blockSchema.icon}`} />
        <h4 className="element-card__title" data-testid="element-card-title">
          {element.title}
        </h4>
        <ElementActions node={element} />
      </div>
      <div
        className={`element-card__content${content === '' ? ' element-card__content--empty' : ''}`}
      >
        {content || 'No preview available'}
      </div>
    </div>
  );
}

function ReadonlyElementCard({ element }: ElementCardProps) {
  const status = getElementStatus(element.statusFlags);
  const content = element.blockSchema.summary;
  const cardClasses = `element-card element-card--${status}`;

  return (
    <div className={cardClasses} data-testid="element-card">
      <div className="element-card__header">
        <i className={`element-card__icon ${element.blockSchema.icon}`} />
        <h4 className="element-card__title" data-testid="element-card-title">
          {element.title}
        </h4>
      </div>
      <div
        className={`element-card__content${content === '' ? ' element-card__content--empty' : ''}`}
      >
        {content || 'No preview available'}
      </div>
    </div>
  );
}
