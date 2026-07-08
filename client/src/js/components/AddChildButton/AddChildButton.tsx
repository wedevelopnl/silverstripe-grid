import { memo } from 'react'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useCreateElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { ContainerType } from '@/types/elements'
import type { NodeRef, NodeType } from '@/types/identity'

interface AddChildButtonProps {
  /**
   * Numeric ID of the parent the new child will attach to — the correct
   * NodeType is inferred from `childType` (a section parent is always a page,
   * a row parent is always a section, a column parent is always a row).
   */
  readonly parentId: number
  readonly childType: ContainerType
  /**
   * - `empty-state` — the only child slot, shown with a hint line above it.
   * - `append` — full-width button after the last child.
   * - `between` — full-width button sitting in the gap between two children;
   *   pair it with {@link insertAfterId} so the new child lands in that gap.
   */
  readonly variant: 'empty-state' | 'append' | 'between'
  /**
   * DB id of the sibling the new child should be inserted *after*. Omit to
   * append at the end of the parent's child list (the backend has no "insert
   * before the first child" path, so a leading slot is intentionally absent).
   */
  readonly insertAfterId?: number
}

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

const AddChildButton = memo(function AddChildButtonComponent({
  parentId,
  childType,
  variant,
  insertAfterId,
}: AddChildButtonProps) {
  const { pageId, zone } = useGridEditorContext()
  const { mutate, isPending } = useCreateElement(pageId, zone)
  const labels = labelsFor(childType)

  function handleClick() {
    const parent: NodeRef = {
      type: PARENT_TYPE_FOR_CHILD[childType],
      id: parentId,
    }

    mutate({
      containerType: childType,
      parent,
      ...(insertAfterId !== undefined ? { insertAfterElementID: insertAfterId } : {}),
      ...(childType === 'section' ? { zone } : {}),
    })
  }

  const button = (
    <button
      type="button"
      className="ssgrid-add-child__button"
      data-testid="add-child-button"
      disabled={isPending}
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

  if (variant === 'between') {
    return (
      <div className="ssgrid-add-child ssgrid-add-child--between" data-testid="add-child-between">
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
