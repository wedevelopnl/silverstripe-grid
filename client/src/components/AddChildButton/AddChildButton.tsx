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
  readonly childLabel: string
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

const AddChildButton = memo(function AddChildButtonComponent({
  parentId,
  childType,
  childLabel,
  variant,
  insertAfterId,
}: AddChildButtonProps) {
  const { pageId, zone } = useGridEditorContext()
  const { mutate, isPending } = useCreateElement(pageId, zone)

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
      <span>
        {isPending
          ? t('WeDevelopGrid.AddChildButton.ADDING_LABEL', 'Adding {childLabel}\u2026', {
              childLabel,
            })
          : t('WeDevelopGrid.AddChildButton.ADD_LABEL', 'Add {childLabel}', { childLabel })}
      </span>
    </button>
  )

  if (variant === 'empty-state') {
    return (
      <div className="ssgrid-add-child ssgrid-add-child--empty" data-testid="add-child-empty">
        <p className="ssgrid-add-child__hint">
          {t('WeDevelopGrid.AddChildButton.EMPTY_MESSAGE', 'No {childLabel}s yet', {
            childLabel: childLabel.toLowerCase(),
          })}
        </p>
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
