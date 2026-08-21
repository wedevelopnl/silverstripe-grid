import { useCallback, useState } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'
import { isInsideSharedBlock, isSharedBlockReferenceNode } from '@/types/elements'
import { containsSharedBlockPlacement } from '@/utils/sharedBlockNesting'
import { useGridEditorContext } from './GridEditorContext'
import { useConvertToSharedBlock } from './useSharedBlockMutations'

export interface ConvertToSharedDialogState {
  readonly isOpen: boolean
  readonly title: string
  readonly message: string
  readonly onConfirm: () => void
  readonly onCancel: () => void
}

interface UseConvertToSharedActionResult {
  readonly action: ActionItem | null
  readonly dialog: ConvertToSharedDialogState | null
}

/**
 * Promote page content into the shared library, leaving a placement behind.
 *
 * Offered only on page-local content that holds no placement of its own: a
 * placement is already shared, content INSIDE a block cannot be shared again,
 * and content that CONTAINS a block would carry it into the new one. All three
 * are the no-nesting rule the server enforces, mirrored here so the action
 * never appears where it would be rejected.
 */
export function useConvertToSharedAction(node: ElementNode): UseConvertToSharedActionResult {
  const { pageId, zone, rootType } = useGridEditorContext()
  const convert = useConvertToSharedBlock(pageId, zone)
  const [isDialogOpen, setDialogOpen] = useState(false)

  const handleOpen = useCallback(() => setDialogOpen(true), [])
  const handleCancel = useCallback(() => setDialogOpen(false), [])

  const handleConfirm = useCallback(() => {
    convert.mutate({ element: node.self, title: node.title })
    setDialogOpen(false)
  }, [convert, node.self, node.title])

  // canCreate mirrors the server gate: converting creates a SharedBlock.
  //
  // rootType is checked as well as isInsideSharedBlock: the library editor's
  // tree is rooted at the BLOCK and contains no placement node, so no node in
  // it carries `sharedBlockKey` and the per-node check sees page-local content
  // everywhere. Without this the action was offered on content already inside
  // a block, and the server refused it on confirm.
  if (
    rootType === 'sharedBlock' ||
    isSharedBlockReferenceNode(node) ||
    isInsideSharedBlock(node) ||
    containsSharedBlockPlacement(node) ||
    !node.canCreate
  ) {
    return { action: null, dialog: null }
  }

  const action: ActionItem = {
    key: 'convert-to-shared',
    label: t('WeDevelopGrid.useConvertToSharedAction.ACTION_LABEL', 'Convert to shared block'),
    onAction: handleOpen,
  }

  const dialog: ConvertToSharedDialogState = {
    isOpen: isDialogOpen,
    title: t('WeDevelopGrid.useConvertToSharedAction.TITLE', 'Convert to shared block'),
    message: t(
      'WeDevelopGrid.useConvertToSharedAction.MESSAGE',
      'Move "{title}" into the shared library. This page keeps showing it, and it becomes available to place on other pages.',
      { title: node.title },
    ),
    onConfirm: handleConfirm,
    onCancel: handleCancel,
  }

  return { action, dialog }
}
