import { useSortable } from '@dnd-kit/sortable'
import { type MouseEvent, memo } from 'react'
import DragHandle from '@/components/DragHandle/DragHandle'
import ElementActions from '@/components/ElementActions/ElementActions'
import { useIsNarrowerThan } from '@/hooks/useIsNarrowerThan'
import { t } from '@/i18n'
import type { SimpleElementNode } from '@/types/elements'
import { buildSortableStyle } from '@/utils/sortableStyles'
import ElementCardChrome from './ElementCardChrome'

interface EditableElementCardProps {
  readonly element: SimpleElementNode
}

/**
 * Content width below which the header's icon action row is folded into the
 * overflow menu.
 *
 * Measured rather than derived: with the title at its 4rem floor, this header
 * wraps onto a second line at 358px of content box. Folding at anything below
 * that leaves a band where the icons neither fit nor collapse, and the row
 * wraps instead — which is the ugly outcome the fold exists to avoid. 360
 * clears it by a hair.
 *
 * The status pill has its own, higher threshold in `element-card.css`: it is
 * an enhancement and goes first, so it never costs the icon row a line.
 */
const NARROW_HEADER_WIDTH = 360

const EditableElementCard = memo(function EditableElementCardComponent({
  element,
}: EditableElementCardProps) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: element.nodeKey,
  })
  const editLink = element.editLink
  const style = buildSortableStyle(transform, transition, isDragging)
  const [headerRef, isNarrow] = useIsNarrowerThan(NARROW_HEADER_WIDTH)

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
    // Stryker disable next-line ConditionalExpression: Equivalent — when interactive is null, currentTarget.contains(null) is already false, so the `!== null` guard is redundant
    if (interactive !== null && event.currentTarget.contains(interactive)) {
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
      onClick={
        // Stryker disable next-line ConditionalExpression: Equivalent — differs only when editLink === null, where href is undefined and ElementCardChrome renders a div with no onClick, so the handler is unused
        editLink !== null ? handleAnchorClick : undefined
      }
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
      headerRef={headerRef}
      trailing={<ElementActions node={element} kebabOnly={isNarrow} />}
    />
  )
})

export default EditableElementCard
