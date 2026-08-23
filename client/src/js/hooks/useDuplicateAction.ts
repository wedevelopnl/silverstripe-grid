import { useCallback } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import { type ElementNode, isSharedBlockRootNode } from '@/types/elements'
import { useEditorRoot } from './GridEditorContext'
import { useDuplicateElement } from './useElementMutations'

interface UseDuplicateActionResult {
  readonly action: ActionItem | null
}

export function useDuplicateAction(node: ElementNode): UseDuplicateActionResult {
  const root = useEditorRoot()
  const duplicateElement = useDuplicateElement(root)

  const handleDuplicate = useCallback(() => {
    // No mutate-level onError: useDuplicateElement already spreads the standard
    // options whose onError toasts. Adding one here fired two identical toasts.
    duplicateElement.mutate(node.self)
  }, [duplicateElement, node.self])

  // Duplicating a block's root would give the block a second root; the server
  // refuses it too.
  if (!node.canCreate || isSharedBlockRootNode(node)) {
    return { action: null }
  }

  const action: ActionItem = {
    key: 'duplicate',
    label: t('WeDevelopGrid.useDuplicateAction.ACTION_LABEL', 'Duplicate'),
    onAction: handleDuplicate,
  }

  return { action }
}
