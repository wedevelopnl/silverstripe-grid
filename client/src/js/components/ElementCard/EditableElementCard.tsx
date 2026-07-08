import { useSortable } from '@dnd-kit/sortable'
import { type MouseEvent, memo } from 'react'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import { t } from '@/i18n'
import type { SimpleElementNode } from '@/types/elements'
import { buildSortableStyle } from '@/utils/sortableStyles'
import ElementCardChrome from './ElementCardChrome'

interface EditableElementCardProps {
  readonly element: SimpleElementNode
}

const EditableElementCard = memo(function EditableElementCardComponent({
  element,
}: EditableElementCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: element.nodeKey,
  })
  const editLink = element.editLink
  const style = buildSortableStyle(transform, transition, isDragging)

  // The edit-link no longer wraps the interactive controls (they are siblings of
  // the anchor, above its stretched ::after), so the old closest()-based
  // click-swallowing is unnecessary. The only remaining guard is to not navigate
  // when a click surfaces at the tail of a drag.
  const handleAnchorClick = (event: MouseEvent<HTMLAnchorElement>) => {
    if (isDragging) {
      event.preventDefault()
    }
  }

  return (
    <ElementCardChrome
      status={element.status}
      icon={element.blockSchema.icon}
      title={element.title}
      summary={element.summary}
      href={editLink ?? undefined}
      onClick={editLink !== null ? handleAnchorClick : undefined}
      setNodeRef={setNodeRef}
      style={style}
      leading={
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.ElementCard.MOVE_LABEL', 'Move {title}', {
            title: element.title,
          })}
        />
      }
      trailing={<ElementActions node={element} />}
    />
  )
})

export default EditableElementCard
