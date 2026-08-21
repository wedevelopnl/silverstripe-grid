import { useCallback, useState } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import { type ElementNode, isSharedBlockRootNode } from '@/types/elements'
import type { NodeRef } from '@/types/identity'
import { type ElementTypeKey, getElementType } from '@/utils/getElementType'
import { useGridEditorContext } from './GridEditorContext'
import { useDuplicateToElement } from './useElementMutations'

interface DuplicateToDialogState {
  readonly isOpen: boolean
  readonly elementType: ElementTypeKey
  readonly onConfirm: (targetPageId: number, targetZone: string, targetParent: NodeRef) => void
  readonly onCancel: () => void
  readonly error: string | null
}

interface UseDuplicateToActionResult {
  readonly action: ActionItem | null
  readonly dialog: DuplicateToDialogState | null
}

export function useDuplicateToAction(node: ElementNode): UseDuplicateToActionResult {
  const { pageId, zone } = useGridEditorContext()
  const duplicateToElement = useDuplicateToElement(pageId, zone)
  const [isDialogOpen, setDialogOpen] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleOpen = useCallback(() => {
    setError(null)
    setDialogOpen(true)
  }, [])

  const handleCancel = useCallback(() => {
    setDialogOpen(false)
    setError(null)
  }, [])

  const handleConfirm = useCallback(
    (targetPageId: number, targetZone: string, targetParent: NodeRef) => {
      duplicateToElement.mutate(
        { element: node.self, targetPageId, targetZone, targetParent },
        {
          onSuccess: () => {
            setDialogOpen(false)
            setError(null)
          },
          onError: (err) => {
            setError(err.message)
          },
        },
      )
    },
    [duplicateToElement, node.self],
  )

  // Copying a block's root out to a page breaks no invariant, but the root
  // carries no duplicate controls at all — the block is the unit here.
  if (!node.canCreate || isSharedBlockRootNode(node)) {
    return { action: null, dialog: null }
  }

  const action: ActionItem = {
    key: 'duplicate-to',
    label: t('WeDevelopGrid.useDuplicateToAction.ACTION_LABEL', 'Duplicate to\u2026'),
    onAction: handleOpen,
  }

  const dialog: DuplicateToDialogState = {
    isOpen: isDialogOpen,
    elementType: getElementType(node),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
    error,
  }

  return { action, dialog }
}
