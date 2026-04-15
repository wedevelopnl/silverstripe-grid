import type { ContainerType } from '@/types/elements';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useCreateElement } from '@/hooks/useElementMutations';
import { t } from '@/i18n';
import type { NodeRef, NodeType } from '@/types/identity';

interface AddChildButtonProps {
  /**
   * Numeric ID of the parent the new child will attach to — the correct
   * NodeType is inferred from `childType` (a section parent is always a page,
   * a row parent is always a section, a column parent is always a row).
   */
  readonly parentId: number;
  readonly childType: ContainerType;
  readonly childLabel: string;
  readonly variant: 'empty-state' | 'append';
}

const PARENT_TYPE_FOR_CHILD: Record<ContainerType, NodeType> = {
  section: 'page',
  row: 'section',
  column: 'row',
};

export default function AddChildButton({
  parentId,
  childType,
  childLabel,
  variant,
}: AddChildButtonProps) {
  const { pageId, zone } = useGridEditorContext();
  const { mutate, isPending } = useCreateElement(pageId, zone);

  function handleClick() {
    const parent: NodeRef = {
      type: PARENT_TYPE_FOR_CHILD[childType],
      id: parentId,
    };

    mutate({
      containerType: childType,
      parent,
      ...(childType === 'section' ? { zone } : {}),
    });
  }

  const button = (
    <button
      type="button"
      className="add-child-button__button"
      data-testid="add-child-button"
      disabled={isPending}
      onClick={handleClick}
    >
      {isPending
        ? t('WeDevelopGrid.AddChildButton.ADDING_LABEL', 'Adding {childLabel}\u2026', {
            childLabel,
          })
        : t('WeDevelopGrid.AddChildButton.ADD_LABEL', 'Add {childLabel}', { childLabel })}
    </button>
  );

  if (variant === 'empty-state') {
    return (
      <div className="add-child-button add-child-button--empty-state" data-testid="add-child-empty">
        <p className="add-child-button__message">
          {t('WeDevelopGrid.AddChildButton.EMPTY_MESSAGE', 'No {childLabel}s yet', {
            childLabel: childLabel.toLowerCase(),
          })}
        </p>
        {button}
      </div>
    );
  }

  return (
    <div className="add-child-button add-child-button--append" data-testid="add-child-append">
      {button}
    </div>
  );
}
