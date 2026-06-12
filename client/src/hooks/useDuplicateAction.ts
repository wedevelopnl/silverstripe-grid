import { useCallback } from 'react'
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'
import { showToast } from '@/utils/toast'
import { useGridEditorContext } from './GridEditorContext'
import { useDuplicateElement } from './useElementMutations'

interface UseDuplicateActionResult {
  readonly action: ActionItem | null
}

export function useDuplicateAction(node: ElementNode): UseDuplicateActionResult {
  const { pageId, zone } = useGridEditorContext()
  const duplicateElement = useDuplicateElement(pageId, zone)

  const handleDuplicate = useCallback(() => {
    duplicateElement.mutate(node.self, {
      onError: (error) => {
        showToast(error.message)
      },
    })
  }, [duplicateElement, node.self])

  if (!node.canCreate) {
    return { action: null }
  }

  const action: ActionItem = {
    key: 'duplicate',
    label: t('WeDevelopGrid.useDuplicateAction.ACTION_LABEL', 'Duplicate'),
    onAction: handleDuplicate,
  }

  return { action }
}
