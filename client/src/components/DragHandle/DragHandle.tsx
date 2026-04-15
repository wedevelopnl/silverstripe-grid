import type { DraggableAttributes, DraggableSyntheticListeners } from '@dnd-kit/core';
import { t } from '@/i18n';
import './DragHandle.scss';

interface DragHandleProps {
  readonly listeners: DraggableSyntheticListeners;
  readonly attributes: DraggableAttributes;
  readonly label?: string;
}

export default function DragHandle({
  listeners,
  attributes,
  label = t('WeDevelopGrid.DragHandle.DEFAULT_LABEL', 'Drag to reorder'),
}: DragHandleProps): React.JSX.Element {
  return (
    <button
      type="button"
      className="drag-handle"
      data-testid="drag-handle"
      aria-label={label}
      {...listeners}
      {...attributes}
    >
      <span className="drag-handle__icon" data-testid="drag-handle-icon" aria-hidden="true" />
    </button>
  );
}
