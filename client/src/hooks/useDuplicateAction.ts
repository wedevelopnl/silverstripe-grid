import { useCallback } from 'react';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';
import type { ElementNode } from '@/types/elements';
import { useGridEditorContext } from './GridEditorContext';
import { useDuplicateElement } from './useElementMutations';
import { showToast } from '@/utils/toast';

interface UseDuplicateActionResult {
  readonly action: ActionItem | null;
}

export function useDuplicateAction(node: ElementNode): UseDuplicateActionResult {
  const { pageId, zone } = useGridEditorContext();
  const duplicateElement = useDuplicateElement(pageId, zone);

  const handleDuplicate = useCallback(() => {
    duplicateElement.mutate(node.id, {
      onError: (error) => {
        showToast(error.message);
      },
    });
  }, [duplicateElement, node.id]);

  if (!node.canCreate) {
    return { action: null };
  }

  const action: ActionItem = {
    key: 'duplicate',
    label: 'Duplicate',
    onAction: handleDuplicate,
  };

  return { action };
}
