import { useCallback, useRef, useState } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'
import type { NodeRef } from '@/types/identity'
import { type ElementTypeKey, getElementType } from '@/utils/getElementType'
import { showToast } from '@/utils/toast'
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
  // Ref mirror of isDialogOpen for the async onError below: the state value
  // captured by the mutate closure is stale by the time the response lands.
  const dialogOpenRef = useRef(false)

  const handleOpen = useCallback(() => {
    setError(null)
    setDialogOpen(true)
    dialogOpenRef.current = true
  }, [])

  const handleCancel = useCallback(() => {
    setDialogOpen(false)
    dialogOpenRef.current = false
    setError(null)
  }, [])

  const handleConfirm = useCallback(
    (targetPageId: number, targetZone: string, targetParent: NodeRef) => {
      duplicateToElement.mutate(
        { element: node.self, targetPageId, targetZone, targetParent },
        {
          onSuccess: () => {
            setDialogOpen(false)
            dialogOpenRef.current = false
            setError(null)
          },
          onError: (err) => {
            // Inline presentation needs a mounted, open dialog. If the user
            // cancelled while the request was in flight, the dialog is closed
            // (and resets its error on reopen), and the hook-level toast is
            // suppressed in favour of the inline message — fall back to a
            // toast so the failure is never presented zero times. (A full
            // component unmount mid-flight still drops this callback; that
            // needs external causes like Pjax navigation and is accepted.)
            if (dialogOpenRef.current) {
              setError(err.message)
            } else {
              showToast(err.message)
            }
          },
        },
      )
    },
    [duplicateToElement, node.self],
  )

  if (!node.canCreate) {
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
