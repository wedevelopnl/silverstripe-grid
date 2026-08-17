import { memo } from 'react'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useCreateElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { ContainerType } from '@/types/elements'
import type { NodeRef, NodeType } from '@/types/identity'

interface AddChildButtonBaseProps {
  /**
   * Numeric ID of the parent the new child will attach to — the correct
   * NodeType is inferred from `childType` (a section parent is always a page,
   * a row parent is always a section, a column parent is always a row).
   */
  readonly parentId: number
  readonly childType: ContainerType
}

/**
 * Placement of the button, and with it the placement of the child it creates:
 *
 * - `empty-state` — the only child slot, shown with a hint line above it.
 * - `append` — full-width button after the last child.
 * - `before-first` — full-width button above the first child; sends
 *   `insertAtStart` so the new child lands before every existing sibling.
 * - `between` — full-width button sitting in the gap between two children;
 *   {@link insertAfterId} is the child to its left, so the new one lands in
 *   that gap.
 *
 * `before-first` and `between` are mutually exclusive at the API level too —
 * the backend rejects `insertAtStart` combined with `insertAfterElementID` —
 * so the union keeps that combination unrepresentable here.
 */
type AddChildButtonProps = AddChildButtonBaseProps &
  (
    | { readonly variant: 'empty-state' }
    | { readonly variant: 'append' }
    | { readonly variant: 'before-first' }
    | { readonly variant: 'between'; readonly insertAfterId: number }
  )

const PARENT_TYPE_FOR_CHILD: Record<ContainerType, NodeType> = {
  section: 'page',
  row: 'section',
  column: 'row',
}

/**
 * Fully translated labels per child type. The nouns are baked into complete
 * per-type keys rather than interpolated: an interpolated English noun ('Add
 * {childLabel}', 'No {childLabel}s yet') left the label untranslated and appended
 * an English plural 's' that no other locale can fix. Literal keys are also
 * required by the i18n collector, so each type is spelled out.
 */
function labelsFor(childType: ContainerType): { add: string; adding: string; empty: string } {
  switch (childType) {
    case 'section':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_SECTION', 'Add Section'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_SECTION', 'Adding Section…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_SECTION', 'No sections yet'),
      }
    case 'row':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_ROW', 'Add Row'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_ROW', 'Adding Row…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_ROW', 'No rows yet'),
      }
    case 'column':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_COLUMN', 'Add Column'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_COLUMN', 'Adding Column…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_COLUMN', 'No columns yet'),
      }
  }
}

const AddChildButton = memo(function AddChildButtonComponent(props: AddChildButtonProps) {
  const { parentId, childType, variant } = props
  const { pageId, zone } = useGridEditorContext()
  const { mutate, isPending } = useCreateElement(pageId, zone)
  const labels = labelsFor(childType)

  function handleClick() {
    // aria-disabled keeps the button focusable, so it can still be clicked —
    // the real guard against a double submit lives here.
    if (isPending) return

    const parent: NodeRef = {
      type: PARENT_TYPE_FOR_CHILD[childType],
      id: parentId,
    }

    const placementParams = ((): { insertAfterElementID?: number; insertAtStart?: boolean } => {
      if (props.variant === 'between') {
        return { insertAfterElementID: props.insertAfterId }
      }
      if (props.variant === 'before-first') {
        return { insertAtStart: true }
      }
      return {}
    })()

    mutate({
      containerType: childType,
      parent,
      ...placementParams,
      ...(childType === 'section' ? { zone } : {}),
    })
  }

  // `aria-disabled` rather than `disabled`: a real disabled button is blurred
  // by the browser, so submitting stranded a keyboard user at the top of the
  // document and silenced the label change that reports progress. Left
  // focusable, the swap to "Adding …" is announced as the focused control's
  // new name.
  const button = (
    <button
      type="button"
      className="ssgrid-add-child__button"
      data-testid="add-child-button"
      aria-disabled={isPending}
      onClick={handleClick}
    >
      <i className="ssgrid-add-child__icon font-icon-plus" aria-hidden="true" />
      <span>{isPending ? labels.adding : labels.add}</span>
    </button>
  )

  if (variant === 'empty-state') {
    return (
      <div className="ssgrid-add-child ssgrid-add-child--empty" data-testid="add-child-empty">
        <p className="ssgrid-add-child__hint">{labels.empty}</p>
        {button}
      </div>
    )
  }

  // Both gap placements share the --between look and the parent list's gap;
  // only the test id distinguishes the leading slot from an interior one.
  if (variant === 'between' || variant === 'before-first') {
    return (
      <div
        className="ssgrid-add-child ssgrid-add-child--between"
        data-testid={variant === 'before-first' ? 'add-child-before-first' : 'add-child-between'}
      >
        {button}
      </div>
    )
  }

  return (
    <div className="ssgrid-add-child ssgrid-add-child--append" data-testid="add-child-append">
      {button}
    </div>
  )
})

export default AddChildButton
