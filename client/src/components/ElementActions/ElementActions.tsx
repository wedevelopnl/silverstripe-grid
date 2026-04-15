import type { ElementNode } from '@/types/elements';
import { useGridEditorContext } from '@/hooks/GridEditorContext';
import { useArchiveAction } from '@/hooks/useArchiveAction';
import { useDuplicateAction } from '@/hooks/useDuplicateAction';
import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';
import { t } from '@/i18n';
import ActionsMenu from '@/components/ActionsMenu/ActionsMenu';
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog';
import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog';

interface ElementActionsProps {
  readonly node: ElementNode;
}

export default function ElementActions({ node }: ElementActionsProps) {
  const { pageId } = useGridEditorContext();
  const { action: archiveAction, dialog: archiveDialog } = useArchiveAction(node);
  const { action: duplicateAction } = useDuplicateAction(node);
  const { action: duplicateToAction, dialog: duplicateToDialog } = useDuplicateToAction(node);

  const actions = [
    ...(duplicateAction !== null ? [duplicateAction] : []),
    ...(duplicateToAction !== null ? [duplicateToAction] : []),
    ...(archiveAction !== null ? [archiveAction] : []),
  ];

  return (
    <>
      <ActionsMenu actions={actions} />
      {archiveDialog?.isOpen && (
        <ConfirmDialog
          isOpen={archiveDialog.isOpen}
          title={archiveDialog.title}
          message={archiveDialog.message}
          confirmLabel={t('WeDevelopGrid.ElementActions.ARCHIVE_CONFIRM_LABEL', 'Archive')}
          onConfirm={archiveDialog.onConfirm}
          onCancel={archiveDialog.onCancel}
          destructive
        />
      )}
      {duplicateToDialog?.isOpen && (
        <DuplicateToDialog
          isOpen={duplicateToDialog.isOpen}
          elementType={duplicateToDialog.elementType}
          currentPageId={pageId}
          onConfirm={duplicateToDialog.onConfirm}
          onCancel={duplicateToDialog.onCancel}
          error={duplicateToDialog.error}
        />
      )}
    </>
  );
}
