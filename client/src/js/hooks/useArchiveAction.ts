import { useCallback, useState } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'
import { countDescendants } from '@/utils/countDescendants'
import { useEditorRoot } from './GridEditorContext'
import { useArchiveElement } from './useElementMutations'

interface ArchiveDialogState {
  readonly isOpen: boolean
  readonly title: string
  readonly message: string
  readonly onConfirm: () => void
  readonly onCancel: () => void
}

interface UseArchiveActionResult {
  readonly action: ActionItem | null
  readonly dialog: ArchiveDialogState | null
}

function buildArchiveMessage(title: string, descendantCount: number): string {
  if (descendantCount === 0) {
    return t('WeDevelopGrid.useArchiveAction.CONFIRM_MESSAGE_SIMPLE', 'Archive "{title}"?', {
      title,
    })
  }

  return descendantCount === 1
    ? t(
        'WeDevelopGrid.useArchiveAction.CONFIRM_MESSAGE_ONE_CHILD',
        'Archive "{title}" and all {count} child element?',
        { title, count: descendantCount },
      )
    : t(
        'WeDevelopGrid.useArchiveAction.CONFIRM_MESSAGE_MANY_CHILDREN',
        'Archive "{title}" and all {count} child elements?',
        { title, count: descendantCount },
      )
}

export function useArchiveAction(node: ElementNode): UseArchiveActionResult {
  const root = useEditorRoot()
  const archiveElement = useArchiveElement(root)
  const [isDialogOpen, setDialogOpen] = useState(false)

  const descendantCount = countDescendants(node)

  const handleOpenDialog = useCallback(() => {
    setDialogOpen(true)
  }, [])

  const handleCancel = useCallback(() => {
    setDialogOpen(false)
  }, [])

  const handleConfirm = useCallback(() => {
    setDialogOpen(false)
    // No mutate-level onError: useArchiveElement already spreads the standard
    // options whose onError toasts. Adding one here fired two identical toasts.
    archiveElement.mutate(node.self)
  }, [archiveElement, node.self])

  if (!node.canDelete) {
    return { action: null, dialog: null }
  }

  const action: ActionItem = {
    key: 'archive',
    label: t('WeDevelopGrid.useArchiveAction.ACTION_LABEL', 'Archive'),
    destructive: true,
    onAction: handleOpenDialog,
  }

  const dialog: ArchiveDialogState = {
    isOpen: isDialogOpen,
    title: t('WeDevelopGrid.useArchiveAction.DIALOG_TITLE', 'Confirm archive'),
    message: buildArchiveMessage(node.title, descendantCount),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
  }

  return { action, dialog }
}
