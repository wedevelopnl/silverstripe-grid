import type { ContainerType } from '@/types/elements';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useCreateElement } from '@/hooks/useElementMutations';

interface AddChildButtonProps {
  readonly parentId: number;
  readonly childType: ContainerType;
  readonly childLabel: string;
  readonly variant: 'empty-state' | 'append';
}

export default function AddChildButton({
  parentId,
  childType,
  childLabel,
  variant,
}: AddChildButtonProps) {
  const { pageId, zone } = useGridEditorContext();
  const { mutate, isPending } = useCreateElement(pageId, zone);

  function handleClick() {
    mutate({
      containerType: childType,
      parentId,
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
      {isPending ? `Adding ${childLabel}…` : `Add ${childLabel}`}
    </button>
  );

  if (variant === 'empty-state') {
    return (
      <div className="add-child-button add-child-button--empty-state" data-testid="add-child-empty">
        <p className="add-child-button__message">No {childLabel.toLowerCase()}s yet</p>
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
