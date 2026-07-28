import type { MouseEvent } from 'react'
import ActionsMenu from '@/components/ActionsMenu/ActionsMenu'
import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog'
import DuplicateToDialog from '@/components/DuplicateToDialog/DuplicateToDialog'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useArchiveAction } from '@/hooks/useArchiveAction'
import { useDuplicateAction } from '@/hooks/useDuplicateAction'
import { useDuplicateToAction } from '@/hooks/useDuplicateToAction'
import { t } from '@/i18n'
import type { ElementNode } from '@/types/elements'

/** Collapse toggle the host block already owns; wires the toolbar's fold icon. */
interface CollapseControl {
  readonly isCollapsed: boolean
  readonly onToggle: () => void
  readonly label: string
}

interface ElementActionsProps {
  readonly node: ElementNode
  /**
   * When provided, the toolbar's "fold" icon toggles this collapse state. (The
   * Figma block toolbar carries a fold control alongside the left-cluster
   * chevron — they drive the same state.) Omit on element cards, which have no
   * collapsed presentation yet — the icon then renders disabled.
   */
  readonly collapse?: CollapseControl
  /**
   * Column headers in the Figma carry only an overflow ellipsis, not the full
   * toolbar — pass `kebabOnly` there to render just the actions menu.
   */
  readonly kebabOnly?: boolean
}

function ToolbarButton({
  glyph,
  label,
  onClick,
  disabled,
  destructive,
  testId,
}: {
  readonly glyph: string
  readonly label: string
  readonly onClick?: () => void
  readonly disabled: boolean
  readonly destructive?: boolean
  readonly testId: string
}) {
  // The block toolbar can sit inside a clickable ElementCard <a>; cancel the
  // anchor's navigation (preventDefault) and stop other React handlers
  // (stopPropagation) before running the action.
  const handleClick =
    onClick === undefined
      ? undefined
      : (event: MouseEvent) => {
          event.preventDefault()
          event.stopPropagation()
          onClick()
        }

  return (
    <button
      type="button"
      className="ssgrid-icon-button"
      data-destructive={destructive === true ? 'true' : undefined}
      data-testid={testId}
      disabled={disabled}
      title={label}
      aria-label={label}
      onClick={handleClick}
    >
      <span className={`ssgrid-icon-button__glyph ${glyph}`} aria-hidden="true" />
    </button>
  )
}

export default function ElementActions({ node, collapse, kebabOnly = false }: ElementActionsProps) {
  const { pageId } = useGridEditorContext()
  const { action: archiveAction, dialog: archiveDialog } = useArchiveAction(node)
  const { action: duplicateAction } = useDuplicateAction(node)
  const { action: duplicateToAction, dialog: duplicateToDialog } = useDuplicateToAction(node)

  const kebabActions = [
    ...(duplicateAction !== null ? [duplicateAction] : []),
    ...(duplicateToAction !== null ? [duplicateToAction] : []),
    ...(archiveAction !== null ? [archiveAction] : []),
  ]

  const dialogs = (
    <>
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
  )

  if (kebabOnly) {
    return (
      <>
        <ActionsMenu actions={kebabActions} />
        {dialogs}
      </>
    )
  }

  const editLink = node.editLink

  const collapseLabel = ((): string => {
    if (collapse === undefined) {
      return t('WeDevelopGrid.ElementActions.ACTION_COLLAPSE', 'Collapse')
    }
    if (collapse.isCollapsed) {
      return t('WeDevelopGrid.ElementActions.EXPAND_LABEL', 'Expand {title}', {
        title: collapse.label,
      })
    }
    return t('WeDevelopGrid.ElementActions.COLLAPSE_LABEL', 'Collapse {title}', {
      title: collapse.label,
    })
  })()

  return (
    <>
      <div
        className="ssgrid-element-toolbar"
        data-testid="element-toolbar"
        role="toolbar"
        aria-label={t('WeDevelopGrid.ElementActions.TOOLBAR_LABEL', 'Element actions')}
      >
        <ToolbarButton
          glyph="font-icon-back-in-time"
          label={t('WeDevelopGrid.ElementActions.ACTION_HISTORY', 'View history')}
          onClick={
            // Stryker disable next-line ConditionalExpression: Equivalent — differs only when editLink === null, and the button is then disabled so onClick never fires
            editLink !== null
              ? () => {
                  // The element's CMS edit form carries a `Root.History` tab
                  // (HistoryViewerField), and SilverStripe renders its tab
                  // anchor as `#Root_History` — append it to land on history.
                  window.location.assign(`${editLink}#Root_History`)
                }
              : undefined
          }
          disabled={editLink === null}
          testId="element-action-history"
        />
        <ToolbarButton
          glyph={
            // Stryker disable next-line all: Equivalent — the glyph value renders only as a font-icon CSS class (visual-only, not a behavioral contract); every mutation here changes which icon class is emitted, observable only via a forbidden className assertion
            collapse?.isCollapsed === true ? 'font-icon-down-open-big' : 'font-icon-up-open-big'
          }
          label={collapseLabel}
          onClick={collapse?.onToggle}
          disabled={collapse === undefined}
          testId="element-action-collapse"
        />
        <ToolbarButton
          glyph="font-icon-clone"
          label={t('WeDevelopGrid.ElementActions.ACTION_DUPLICATE', 'Duplicate')}
          onClick={duplicateAction?.onAction}
          disabled={duplicateAction === null}
          testId="element-action-duplicate"
        />
        <ToolbarButton
          glyph="font-icon-external-link"
          label={t('WeDevelopGrid.ElementActions.ACTION_OPEN_NEW', 'Open in a new tab')}
          onClick={
            editLink !== null
              ? () => {
                  window.open(editLink, '_blank', 'noopener,noreferrer')
                }
              : undefined
          }
          disabled={editLink === null}
          testId="element-action-open"
        />
        <ToolbarButton
          glyph="font-icon-pencil"
          label={t('WeDevelopGrid.ElementActions.ACTION_EDIT', 'Edit')}
          onClick={
            editLink !== null
              ? () => {
                  window.location.assign(editLink)
                }
              : undefined
          }
          disabled={editLink === null}
          testId="element-action-edit"
        />
        <ToolbarButton
          glyph="font-icon-trash-bin"
          label={t('WeDevelopGrid.ElementActions.ACTION_ARCHIVE', 'Archive')}
          onClick={archiveAction?.onAction}
          disabled={archiveAction === null}
          destructive
          testId="element-action-archive"
        />
        {duplicateToAction !== null && <ActionsMenu actions={[duplicateToAction]} />}
      </div>
      {dialogs}
    </>
  )
}
