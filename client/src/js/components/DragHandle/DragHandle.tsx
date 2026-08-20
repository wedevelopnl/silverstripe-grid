import type { DraggableAttributes, DraggableSyntheticListeners } from '@dnd-kit/core'
import { memo } from 'react'
import { t } from '@/i18n'

interface DragHandleProps {
  readonly listeners: DraggableSyntheticListeners
  readonly attributes: DraggableAttributes
  readonly label?: string
}

const DragHandle = memo(function DragHandleComponent({
  listeners,
  attributes,
  label = t('WeDevelopGrid.DragHandle.DEFAULT_LABEL', 'Drag to reorder'),
}: DragHandleProps): React.JSX.Element {
  return (
    <button
      type="button"
      className="ssgrid-icon-button ssgrid-focus-ring"
      data-variant="drag"
      data-testid="drag-handle"
      aria-label={label}
      {...listeners}
      {...attributes}
    >
      <span
        data-testid="drag-handle-icon"
        className="ssgrid-glyph font-icon-drag-handle"
        aria-hidden="true"
      />
    </button>
  )
})

export default DragHandle
