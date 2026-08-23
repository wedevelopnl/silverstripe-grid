import { useCallback, useState } from 'react'
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog'
import { ToolbarButton } from '@/components/ElementActions/ElementActions'
import { useEditorRoot } from '@/hooks/GridEditorContext'
import { useRovingToolbar } from '@/hooks/useRovingToolbar'
import { useArchiveElement } from '@/hooks/useElementMutations'
import { t } from '@/i18n'
import type { SharedBlockReferenceNode } from '@/types/elements'

interface SharedPlacementActionsProps {
  readonly placement: SharedBlockReferenceNode
}

/**
 * View, edit and remove for a placed shared block, on the frame's own bar
 * beside its overflow menu.
 *
 * A block's content is read-only on the page — editing it inline would change
 * every page that places it, invisibly — so view and edit lead to the BLOCK's
 * form in the library, not to the element that happens to root it. Remove
 * archives the PLACEMENT only; the block and its content stay in the library
 * and on every other page.
 */
export default function SharedPlacementActions({ placement }: SharedPlacementActionsProps) {
  const root = useEditorRoot()
  const archiveElement = useArchiveElement(root)
  const [isConfirmOpen, setConfirmOpen] = useState(false)

  const confirmRemove = useCallback(() => {
    setConfirmOpen(false)
    archiveElement.mutate(placement.self)
  }, [archiveElement, placement.self])

  const { title, editLink } = placement.sharedBlock

  const actions = [
    {
      key: 'open',
      label: t('WeDevelopGrid.SharedPlacementActions.ACTION_OPEN_NEW', 'Open in a new tab'),
      glyph: 'font-icon-external-link',
      onAction:
        editLink !== null
          ? () => {
              window.open(editLink, '_blank', 'noopener,noreferrer')
            }
          : undefined,
    },
    {
      key: 'edit',
      label: t(
        'WeDevelopGrid.SharedPlacementActions.ACTION_EDIT',
        'Edit in the shared block library',
      ),
      glyph: 'font-icon-pencil',
      onAction:
        editLink !== null
          ? () => {
              window.location.assign(editLink)
            }
          : undefined,
    },
    {
      key: 'remove',
      label: t('WeDevelopGrid.SharedPlacementActions.ACTION_REMOVE', 'Remove from this page'),
      glyph: 'font-icon-trash-bin',
      destructive: true,
      onAction: placement.canDelete ? () => setConfirmOpen(true) : undefined,
    },
  ]

  // Same one-tab-stop toolbar as the element cards': only enabled buttons are
  // stops, so a placement whose block has no edit link contributes none.
  const enabledKeys = actions.filter((a) => a.onAction !== undefined).map((a) => a.key)
  const toolbar = useRovingToolbar({ stopCount: enabledKeys.length })

  return (
    <>
      <div
        ref={toolbar.toolbarRef}
        className="ssgrid-element-toolbar"
        data-testid="shared-placement-toolbar"
        role="toolbar"
        aria-label={t('WeDevelopGrid.SharedPlacementActions.TOOLBAR_LABEL', 'Shared block actions')}
        onKeyDown={toolbar.handleKeyDown}
      >
        {actions.map((action) => (
          <ToolbarButton
            key={action.key}
            glyph={action.glyph}
            label={action.label}
            onClick={action.onAction}
            disabled={action.onAction === undefined}
            destructive={action.destructive}
            testId={`element-action-${action.key}`}
            tabIndex={enabledKeys.indexOf(action.key) === toolbar.activeStop ? 0 : -1}
          />
        ))}
      </div>

      {isConfirmOpen && (
        <ConfirmDialog
          isOpen
          destructive
          title={t('WeDevelopGrid.SharedPlacementActions.REMOVE_TITLE', 'Remove shared block')}
          message={t(
            'WeDevelopGrid.SharedPlacementActions.REMOVE_MESSAGE',
            'Remove "{title}" from this page? The shared block stays in the library and on any other page that places it.',
            { title },
          )}
          confirmLabel={t('WeDevelopGrid.SharedPlacementActions.REMOVE_CONFIRM', 'Remove')}
          onConfirm={confirmRemove}
          onCancel={() => setConfirmOpen(false)}
        />
      )}
    </>
  )
}
