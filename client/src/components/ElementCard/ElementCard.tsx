import { useSortable } from '@dnd-kit/sortable'
import { type MouseEvent, memo, type ReactNode } from 'react'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import { useReadonly } from '@/hooks/ReadonlyContext'
import { t } from '@/i18n'
import type { SimpleElementNode } from '@/types/elements'
import { buildSortableStyle } from '@/utils/sortableStyles'

interface ElementCardProps {
  readonly element: SimpleElementNode
}

/**
 * Element card dispatcher: picks the editable or readonly variant
 * based on the `ReadonlyContext`. The readonly variant drops
 * `useSortable`, navigation callbacks, and interactive controls —
 * just renders the icon and title inside the status-colored border.
 */
const ElementCard = memo(function ElementCardComponent({ element }: ElementCardProps) {
  const readonly = useReadonly()
  return readonly ? (
    <ReadonlyElementCard element={element} />
  ) : (
    <EditableElementCard element={element} />
  )
})

export default ElementCard

function EditableElementCard({ element }: ElementCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: element.nodeKey,
  })
  const status = element.status
  const editLink = element.editLink
  const isClickable = editLink !== null

  const style = buildSortableStyle(transform, transition, isDragging)

  const header: ReactNode = (
    <>
      <div className="ssgrid-block__header">
        <DragHandle
          listeners={listeners}
          attributes={attributes}
          label={t('WeDevelopGrid.ElementCard.MOVE_LABEL', 'Move {title}', {
            title: element.title,
          })}
        />
        <i
          className={`ssgrid-block__icon ${element.blockSchema.icon}`}
          data-testid="element-card-icon"
          aria-hidden="true"
        />
        <h4 className="ssgrid-block__title" data-testid="element-card-title">
          {element.title}
        </h4>
        {status === 'modified' && (
          <span
            className="ssgrid-modified-dot"
            data-testid="element-card-modified-indicator"
            aria-label={t('WeDevelopGrid.ElementCard.MODIFIED_LABEL', 'Has unpublished changes')}
            role="img"
          />
        )}
        <ElementActions node={element} />
      </div>
      {element.summary ? (
        <p className="ssgrid-block__body" data-testid="element-card-summary">
          {element.summary}
        </p>
      ) : null}
    </>
  )

  if (isClickable) {
    // Swallow clicks from interactive descendants (drag handle, actions menu,
    // nested buttons/links/inputs) or while a drag is in progress — the anchor
    // would otherwise navigate when the user interacts with those controls or
    // releases a drag. A plain center-click on the card (or a click on the
    // title text / content body) still navigates because those targets have
    // no interactive ancestor inside the card other than the anchor itself.
    const handleAnchorClick = (event: MouseEvent<HTMLAnchorElement>) => {
      if (isDragging) {
        event.preventDefault()
        return
      }
      if (!(event.target instanceof Element)) {
        return
      }
      const interactive = event.target.closest(
        'button, input, select, textarea, [role="button"], [role="menuitem"], [role="listbox"], [role="dialog"]',
      )
      if (interactive !== null && event.currentTarget.contains(interactive)) {
        event.preventDefault()
      }
    }

    return (
      <a
        ref={setNodeRef}
        href={editLink}
        style={style}
        className="ssgrid-block"
        data-testid="element-card"
        data-state="clickable"
        data-status={status}
        onClick={handleAnchorClick}
      >
        {header}
      </a>
    )
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="ssgrid-block"
      data-testid="element-card"
      data-status={status}
    >
      {header}
    </div>
  )
}

function ReadonlyElementCard({ element }: ElementCardProps) {
  const status = element.status

  return (
    <div className="ssgrid-block" data-testid="element-card" data-status={status}>
      <div className="ssgrid-block__header">
        <i
          className={`ssgrid-block__icon ${element.blockSchema.icon}`}
          data-testid="element-card-icon"
          aria-hidden="true"
        />
        <h4 className="ssgrid-block__title" data-testid="element-card-title">
          {element.title}
        </h4>
        {status === 'modified' && (
          <span
            className="ssgrid-modified-dot"
            data-testid="element-card-modified-indicator"
            aria-label={t('WeDevelopGrid.ElementCard.MODIFIED_LABEL', 'Has unpublished changes')}
            role="img"
          />
        )}
      </div>
      {element.summary ? (
        <p className="ssgrid-block__body" data-testid="element-card-summary">
          {element.summary}
        </p>
      ) : null}
    </div>
  )
}
