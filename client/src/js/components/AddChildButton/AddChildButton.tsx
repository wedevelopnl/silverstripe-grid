import { memo, useState } from 'react'
import PlacementMenu from '@/components/PlacementMenu/PlacementMenu'
import SharedBlockPickerDialog from '@/components/SharedBlockPickerDialog/SharedBlockPickerDialog'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useCreateElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { ContainerType } from '@/types/elements'
import type { NodeRef, NodeType } from '@/types/identity'
import type { SharedBlockParentType } from '@/types/sharedBlocks'

interface AddChildButtonBaseProps {
  /**
   * Numeric ID of the parent the new child will attach to — the correct
   * NodeType is inferred from `childType` (a section parent is always a page,
   * a row parent is always a section, a column parent is always a row).
   */
  readonly parentId: number
  readonly childType: ContainerType
  /**
   * Overrides the inferred parent NodeType. Only the library editor needs it:
   * there a Section's parent is the SharedBlock that roots the tree, not a page.
   *
   * Narrower than `NodeType` on purpose: a container's parent is never a leaf
   * element, and excluding that case is what lets the shared-block picker take
   * this value directly once `'sharedBlock'` is ruled out.
   */
  readonly parentType?: SharedBlockParentType | 'sharedBlock'
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

/**
 * The parent kind a child of this type attaches to. Serves both the create call
 * and the shared-block picker's library filter: the two unions overlap exactly
 * on the three values held here.
 */
const PARENT_TYPE_FOR_CHILD: Record<ContainerType, NodeType & SharedBlockParentType> = {
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
function labelsFor(childType: ContainerType): {
  add: string
  adding: string
  empty: string
  shared: string
  sharedTrigger: string
} {
  switch (childType) {
    case 'section':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_SECTION', 'Add Section'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_SECTION', 'Adding Section…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_SECTION', 'No sections yet'),
        shared: t('WeDevelopGrid.AddChildButton.PLACE_SHARED_SECTION', 'Place shared section…'),
        sharedTrigger: t('WeDevelopGrid.AddChildButton.MORE_SECTION', 'More ways to add a section'),
      }
    case 'row':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_ROW', 'Add Row'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_ROW', 'Adding Row…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_ROW', 'No rows yet'),
        shared: t('WeDevelopGrid.AddChildButton.PLACE_SHARED_ROW', 'Place shared row…'),
        sharedTrigger: t('WeDevelopGrid.AddChildButton.MORE_ROW', 'More ways to add a row'),
      }
    case 'column':
      return {
        add: t('WeDevelopGrid.AddChildButton.ADD_COLUMN', 'Add Column'),
        adding: t('WeDevelopGrid.AddChildButton.ADDING_COLUMN', 'Adding Column…'),
        empty: t('WeDevelopGrid.AddChildButton.EMPTY_COLUMN', 'No columns yet'),
        shared: t('WeDevelopGrid.AddChildButton.PLACE_SHARED_COLUMN', 'Place shared column…'),
        sharedTrigger: t('WeDevelopGrid.AddChildButton.MORE_COLUMN', 'More ways to add a column'),
      }
  }
}

const AddChildButton = memo(function AddChildButtonComponent(props: AddChildButtonProps) {
  const { parentId, childType, variant } = props
  const { pageId, zone, rootType } = useGridEditorContext()
  // The whole library-editor tree is inside a block, root node included.
  const isLibraryEditor = rootType === 'sharedBlock'
  const { mutate, isPending } = useCreateElement(pageId, zone)
  const labels = labelsFor(childType)
  const [isSharedPickerOpen, setSharedPickerOpen] = useState(false)

  const parentType = props.parentType ?? PARENT_TYPE_FOR_CHILD[childType]

  // One source of truth for where the child lands, shared by the create call
  // and the placement of a block chosen through the caret.
  const placementParams = ((): { insertAfterElementID?: number; insertAtStart?: boolean } => {
    if (props.variant === 'between') {
      return { insertAfterElementID: props.insertAfterId }
    }
    if (props.variant === 'before-first') {
      return { insertAtStart: true }
    }
    return {}
  })()

  function handleClick() {
    // aria-disabled keeps the button focusable, so it can still be clicked —
    // the real guard against a double submit lives here.
    if (isPending) return

    const parent: NodeRef = { type: parentType, id: parentId }

    mutate({
      containerType: childType,
      parent,
      ...placementParams,
      // Zone scopes page roots only. The library editor's zone is '' (a block
      // has none), which the server rejects as an empty string before the
      // service — which clears the zone for a block parent anyway — is reached.
      ...(childType === 'section' && parentType === 'page' ? { zone } : {}),
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
      className="ssgrid-add-child-button ssgrid-focus-ring"
      data-testid="add-child-button"
      aria-disabled={isPending}
      onClick={handleClick}
    >
      <i className="ssgrid-glyph ssgrid-add-child-icon font-icon-plus" aria-hidden="true" />
      <span>{isPending ? labels.adding : labels.add}</span>
    </button>
  )

  // A block may not contain a block, so nothing in the library editor offers
  // the shared route. `rootType` is what covers the whole tree there:
  // `parentType` is 'sharedBlock' only on the root empty-state button, so
  // gating on it alone left every nested add strip inside a block still
  // offering a placement the server then rejected with SHARED_NESTING.
  const sharedMenu =
    isLibraryEditor || parentType === 'sharedBlock' ? null : (
      <PlacementMenu
        variant="strip"
        testId="add-child-shared"
        triggerLabel={labels.sharedTrigger}
        items={[{ key: 'shared', label: labels.shared, onSelect: () => setSharedPickerOpen(true) }]}
      />
    )

  // Button and caret share one dashed outline: the split IS the control, so the
  // border lives on the wrapper and the button contributes only its own half.
  const control = (
    <div className="ssgrid-add-child-split">
      {button}
      {sharedMenu}
    </div>
  )

  // `parentType` narrows to NodeType & SharedBlockParentType only after the
  // `!== 'sharedBlock'` guard, which is why the picker is gated on it a second
  // time rather than reusing `sharedMenu !== null` — a derived boolean is not a
  // type predicate.
  const picker = isSharedPickerOpen && !isLibraryEditor && parentType !== 'sharedBlock' && (
    <SharedBlockPickerDialog
      parentType={parentType}
      parent={{ type: parentType, id: parentId }}
      zone={childType === 'section' ? zone : undefined}
      {...placementParams}
      isOpen={isSharedPickerOpen}
      onClose={() => setSharedPickerOpen(false)}
    />
  )

  if (variant === 'empty-state') {
    return (
      <>
        <div className="ssgrid-add-child" data-variant="empty" data-testid="add-child-empty">
          <p className="ssgrid-add-child-hint">{labels.empty}</p>
          {control}
        </div>
        {picker}
      </>
    )
  }

  // Both gap placements share the between look and the parent list's gap;
  // only the test id distinguishes the leading slot from an interior one.
  if (variant === 'between' || variant === 'before-first') {
    return (
      <>
        <div
          className="ssgrid-add-child"
          data-variant="between"
          data-testid={variant === 'before-first' ? 'add-child-before-first' : 'add-child-between'}
        >
          {control}
        </div>
        {picker}
      </>
    )
  }

  return (
    <>
      <div className="ssgrid-add-child" data-variant="append" data-testid="add-child-append">
        {control}
      </div>
      {picker}
    </>
  )
})

export default AddChildButton
