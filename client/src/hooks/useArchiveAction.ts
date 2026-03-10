import { useCallback, useState } from 'react';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';
import type { ElementNode } from '@/types/elements';
import { useGridEditorContext } from './GridEditorContext';
import { useDeleteElement } from './useElementMutations';
import { countDescendants } from '@/utils/countDescendants';
import { showToast } from '@/utils/toast';

interface ArchiveDialogState {
  readonly isOpen: boolean;
  readonly title: string;
  readonly message: string;
  readonly onConfirm: () => void;
  readonly onCancel: () => void;
}

interface UseArchiveActionResult {
  readonly action: ActionItem | null;
  readonly dialog: ArchiveDialogState | null;
}

function buildArchiveMessage(title: string, descendantCount: number): string {
  if (descendantCount === 0) {
    return `Archive "${title}"?`;
  }

  const suffix = descendantCount === 1 ? 'child element' : 'child elements';
  return `Archive "${title}" and all ${descendantCount} ${suffix}?`;
}

export function useArchiveAction(node: ElementNode): UseArchiveActionResult {
  const { pageId, zone } = useGridEditorContext();
  const deleteElement = useDeleteElement(pageId, zone);
  const [isDialogOpen, setDialogOpen] = useState(false);

  const descendantCount = countDescendants(node);

  const handleOpenDialog = useCallback(() => {
    setDialogOpen(true);
  }, []);

  const handleCancel = useCallback(() => {
    setDialogOpen(false);
  }, []);

  const handleConfirm = useCallback(() => {
    setDialogOpen(false);
    deleteElement.mutate(node.id, {
      onError: (error) => {
        showToast(error.message);
      },
    });
  }, [deleteElement, node.id]);

  if (!node.canDelete) {
    return { action: null, dialog: null };
  }

  const action: ActionItem = {
    key: 'archive',
    label: 'Archive',
    destructive: true,
    onAction: handleOpenDialog,
  };

  const dialog: ArchiveDialogState = {
    isOpen: isDialogOpen,
    title: 'Confirm archive',
    message: buildArchiveMessage(node.title, descendantCount),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
  };

  return { action, dialog };
}
