import { memo } from 'react'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useCreateElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { NodeRef } from '@/types/identity'

/**
 * The Figma column-insert affordances on a Row.
 *
 * Three placements, all creating a new Column under the row:
 * - `start` — the always-visible "+" square flanking the row on the left;
 *   sends `insertAtStart` so the new column lands before every existing one.
 * - `end` — the always-visible "+" square flanking the row on the right;
 *   inserts directly after the last column (i.e. appends).
 * - `between` — a small dot handle sitting in a column gutter; on hover it
 *   blooms into a "+" square. Inserts directly after the column to its left.
 *   When the column it precedes carries a grid offset, `gutterShiftPct` nudges
 *   it into the centre of that (wider) gutter instead of hugging the column.
 *
 * `start` is the one placement the create API couldn't express before the
 * `insertAtStart` flag — `insertAfterElementID` only ever appended or slotted
 * after an existing sibling, with no "before the first child" option.
 */
type ColumnInsertButtonProps =
  | { readonly rowId: number; readonly placement: 'start' }
  | { readonly rowId: number; readonly placement: 'end'; readonly afterColumnId: number }
  | {
      readonly rowId: number
      readonly placement: 'between'
      readonly afterColumnId: number
      /**
       * Shift the handle left by this percentage of the column's width so it
       * lands in the centre of the (offset-widened) gutter rather than glued to
       * the column's edge. 0 / omitted = the default 16px gutter, no shift.
       */
      readonly gutterShiftPct?: number
    }

const ColumnInsertButton = memo(function ColumnInsertButtonComponent(
  props: ColumnInsertButtonProps,
) {
  const { pageId, zone } = useGridEditorContext()
  const { mutate, isPending } = useCreateElement(pageId, zone)

  function handleClick() {
    // aria-disabled keeps the button focusable, so the click still lands — this
    // guard is what prevents a double submit.
    if (isPending) return

    const parent: NodeRef = { type: 'row', id: props.rowId }
    const placementParams =
      props.placement === 'start'
        ? { insertAtStart: true }
        : { insertAfterElementID: props.afterColumnId }
    mutate({ containerType: 'column', parent, ...placementParams })
  }

  const label = ((): string => {
    if (props.placement === 'start') {
      return t('WeDevelopGrid.ColumnInsertButton.PREPEND_LABEL', 'Add a column at the start')
    }
    if (props.placement === 'end') {
      return t('WeDevelopGrid.ColumnInsertButton.APPEND_LABEL', 'Add a column at the end')
    }
    return t('WeDevelopGrid.ColumnInsertButton.INSERT_HERE_LABEL', 'Add a column here')
  })()

  const shiftStyle =
    props.placement === 'between' && props.gutterShiftPct
      ? ({ '--ssgrid-insert-shift': `${props.gutterShiftPct}%` } as React.CSSProperties)
      : undefined

  return (
    <button
      type="button"
      className="ssgrid-column-insert"
      data-placement={props.placement}
      data-testid={`column-insert-${props.placement}`}
      style={shiftStyle}
      // See AddChildButton: a real `disabled` is blurred by the browser, which
      // strands keyboard focus mid-insert.
      aria-disabled={isPending}
      onClick={handleClick}
      title={label}
      aria-label={label}
    >
      <span className="ssgrid-column-insert__dot" aria-hidden="true" />
      <i className="ssgrid-column-insert__icon font-icon-plus" aria-hidden="true" />
    </button>
  )
})

export default ColumnInsertButton
