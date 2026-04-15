import { useCallback, useState } from 'react';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';
import type { ElementNode } from '@/types/elements';
import { getElementType } from '@/utils/getElementType';
import { useGridEditorContext } from './GridEditorContext';
import { useDuplicateToElement } from './useElementMutations';
import { t } from '@/i18n';

interface DuplicateToDialogState {
  readonly isOpen: boolean;
  readonly elementType: string;
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParentId: number) => void;
  readonly onCancel: () => void;
  readonly error: string | null;
}

interface UseDuplicateToActionResult {
  readonly action: ActionItem | null;
  readonly dialog: DuplicateToDialogState | null;
}

export function useDuplicateToAction(node: ElementNode): UseDuplicateToActionResult {
  const { pageId, zone } = useGridEditorContext();
  const duplicateToElement = useDuplicateToElement(pageId, zone);
  const [isDialogOpen, setDialogOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleOpen = useCallback(() => {
    setError(null);
    setDialogOpen(true);
  }, []);

  const handleCancel = useCallback(() => {
    setDialogOpen(false);
    setError(null);
  }, []);

  const handleConfirm = useCallback(
    (targetPageId: number, targetZone: string, targetParentId: number) => {
      duplicateToElement.mutate(
        { id: node.id, targetPageId, targetZone, targetParentId },
        {
          onSuccess: () => {
            setDialogOpen(false);
            setError(null);
          },
          onError: (err) => {
            setError(err.message);
          },
        },
      );
    },
    [duplicateToElement, node.id],
  );

  if (!node.canCreate) {
    return { action: null, dialog: null };
  }

  const action: ActionItem = {
    key: 'duplicate-to',
    label: t('WeDevelopGrid.useDuplicateToAction.ACTION_LABEL', 'Duplicate to\u2026'),
    onAction: handleOpen,
  };

  const dialog: DuplicateToDialogState = {
    isOpen: isDialogOpen,
    elementType: getElementType(node),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
    error,
  };

  return { action, dialog };
}
