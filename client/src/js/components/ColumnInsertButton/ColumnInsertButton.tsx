import { memo, useState } from 'react'
import PlacementMenu from '@/components/PlacementMenu/PlacementMenu'
import SharedBlockPickerDialog from '@/components/SharedBlockPickerDialog/SharedBlockPickerDialog'
import { useEditorRoot } from '@/hooks/GridEditorContext'
import { useCreateElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { NodeRef } from '@/types/identity'

/**
 * The Figma column-insert affordances on a Row: a split control pairing the "+"
 * button with the caret of {@link PlacementMenu}, which places a column-rooted
 * shared block at the same position.
 *
 * Three placements, all adding a Column under the row:
 * - `start` — the always-visible pill flanking the row on the left; sends
 *   `insertAtStart` so the new column lands before every existing one.
 * - `end` — the always-visible pill flanking the row on the right; inserts
 *   directly after the last column (i.e. appends).
 * - `between` — a small dot handle sitting in a column gutter; on hover it
 *   blooms into the same split pill. Inserts directly after the column to its
 *   left. When the column it precedes carries a grid offset, `gutterShiftPct`
 *   nudges it into the centre of that (wider) gutter instead of hugging the
 *   column.
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
  const root = useEditorRoot()
  // A block may not contain a block — see AddChildButton for the same gate.
  const isLibraryEditor = root.kind === 'sharedBlock'
  const { mutate, isPending } = useCreateElement(root)
  const [isSharedPickerOpen, setSharedPickerOpen] = useState(false)

  // One source of truth for where the column lands, shared by the create call
  // and the placement of a block chosen through the caret: `start` goes before
  // every sibling, the other two land after the column named by afterColumnId.
  const placementParams =
    props.placement === 'start'
      ? { insertAtStart: true }
      : { insertAfterElementID: props.afterColumnId }

  function handleClick() {
    // aria-disabled keeps the button focusable, so the click still lands — this
    // guard is what prevents a double submit.
    if (isPending) return

    const parent: NodeRef = { type: 'row', id: props.rowId }
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

  // data-testid and style stay on the wrapper: ColumnBlock's tests read
  // --ssgrid-insert-shift off `column-insert-between`, and the wrapper is also
  // the positioned element that var feeds via inset-inline-start.
  //
  // `data-split` reports whether the caret half is there: without it the pill
  // is only as wide as the "+" square, and the library editor's caret-less
  // control would otherwise keep the split's width and its squared-off trailing
  // corners.
  return (
    <span
      className="ssgrid-column-insert"
      data-placement={props.placement}
      data-split={isLibraryEditor ? undefined : ''}
      data-testid={`column-insert-${props.placement}`}
      style={shiftStyle}
    >
      <button
        type="button"
        className="ssgrid-column-insert-add ssgrid-focus-ring"
        data-testid={`column-insert-${props.placement}-add`}
        // See AddChildButton: a real `disabled` is blurred by the browser, which
        // strands keyboard focus mid-insert.
        aria-disabled={isPending}
        onClick={handleClick}
        title={label}
        aria-label={label}
      >
        <span className="ssgrid-column-insert-dot" aria-hidden="true" />
        <i className="ssgrid-glyph ssgrid-column-insert-icon font-icon-plus" aria-hidden="true" />
      </button>
      {!isLibraryEditor && (
        <PlacementMenu
          variant="chip"
          testId="column-insert-shared"
          triggerLabel={t(
            'WeDevelopGrid.ColumnInsertButton.MORE_COLUMN',
            'More ways to add a column',
          )}
          items={[
            {
              key: 'shared',
              label: t(
                'WeDevelopGrid.ColumnInsertButton.PLACE_SHARED_COLUMN',
                'Place shared column…',
              ),
              onSelect: () => setSharedPickerOpen(true),
            },
          ]}
        />
      )}
      {isSharedPickerOpen && !isLibraryEditor && (
        <SharedBlockPickerDialog
          parentType="row"
          parent={{ type: 'row', id: props.rowId }}
          {...placementParams}
          isOpen={isSharedPickerOpen}
          onClose={() => setSharedPickerOpen(false)}
        />
      )}
    </span>
  )
})

export default ColumnInsertButton
