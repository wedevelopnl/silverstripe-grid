import { type MouseEvent, useRef, useState } from 'react'
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
   * Render every action inside the overflow menu instead of as an icon row.
   *
   * Always on for column headers, which the Figma gives only an ellipsis. Also
   * switched on by element cards once their column is too narrow to fit the
   * icon row without crushing the title — see `useIsNarrowerThan`.
   */
  readonly kebabOnly?: boolean
}

/**
 * One action, rendered either as a toolbar icon or as an overflow menu item.
 *
 * `onAction: undefined` means unavailable — the icon row shows it disabled (so
 * the toolbar keeps a stable shape), while the menu omits it outright, since a
 * dead row in a popup is just noise.
 */
interface ElementAction {
  readonly key: string
  readonly label: string
  readonly glyph: string
  readonly onAction?: () => void
  readonly destructive?: boolean
  /** Has no icon in the design, so it only ever appears in the overflow menu. */
  readonly overflowOnly?: boolean
}

function ToolbarButton({
  glyph,
  label,
  onClick,
  disabled,
  destructive,
  testId,
  tabIndex,
}: {
  readonly glyph: string
  readonly label: string
  readonly onClick?: () => void
  readonly disabled: boolean
  readonly destructive?: boolean
  readonly testId: string
  readonly tabIndex: number
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
      className="ssgrid-icon-button ssgrid-focus-ring"
      data-destructive={destructive === true ? 'true' : undefined}
      data-testid={testId}
      disabled={disabled}
      tabIndex={tabIndex}
      title={label}
      aria-label={label}
      onClick={handleClick}
    >
      <span className={`ssgrid-glyph ${glyph}`} aria-hidden="true" />
    </button>
  )
}

export default function ElementActions({ node, collapse, kebabOnly = false }: ElementActionsProps) {
  const { pageId } = useGridEditorContext()
  const toolbarRef = useRef<HTMLDivElement>(null)
  const [activeIndex, setActiveIndex] = useState(0)
  const { action: archiveAction, dialog: archiveDialog } = useArchiveAction(node)
  const { action: duplicateAction } = useDuplicateAction(node)
  const { action: duplicateToAction, dialog: duplicateToDialog } = useDuplicateToAction(node)

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

  // Single source of truth for the action set, in the Figma's toolbar order.
  // Both presentations below read from this, so a narrow card can never end up
  // offering a different set of actions than a wide one.
  const actions: readonly ElementAction[] = [
    {
      key: 'history',
      label: t('WeDevelopGrid.ElementActions.ACTION_HISTORY', 'View history'),
      glyph: 'font-icon-back-in-time',
      onAction:
        // Stryker disable next-line ConditionalExpression: Equivalent — differs only when editLink === null, and the action is then unavailable so it never fires
        editLink !== null
          ? () => {
              // The element's CMS edit form carries a `Root.History` tab
              // (HistoryViewerField), and SilverStripe renders its tab
              // anchor as `#Root_History` — append it to land on history.
              window.location.assign(`${editLink}#Root_History`)
            }
          : undefined,
    },
    {
      key: 'collapse',
      label: collapseLabel,
      glyph:
        // Stryker disable next-line all: Equivalent — the glyph value renders only as a font-icon CSS class (visual-only, not a behavioral contract); every mutation here changes which icon class is emitted, observable only via a forbidden className assertion
        collapse?.isCollapsed === true ? 'font-icon-down-open-big' : 'font-icon-up-open-big',
      onAction: collapse?.onToggle,
    },
    {
      key: 'duplicate',
      label: t('WeDevelopGrid.ElementActions.ACTION_DUPLICATE', 'Duplicate'),
      glyph: 'font-icon-clone',
      onAction: duplicateAction?.onAction,
    },
    {
      key: 'open',
      label: t('WeDevelopGrid.ElementActions.ACTION_OPEN_NEW', 'Open in a new tab'),
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
      label: t('WeDevelopGrid.ElementActions.ACTION_EDIT', 'Edit'),
      glyph: 'font-icon-pencil',
      onAction:
        editLink !== null
          ? () => {
              window.location.assign(editLink)
            }
          : undefined,
    },
    {
      key: 'archive',
      label: t('WeDevelopGrid.ElementActions.ACTION_ARCHIVE', 'Archive'),
      glyph: 'font-icon-trash-bin',
      destructive: true,
      onAction: archiveAction?.onAction,
    },
    {
      key: 'duplicate-to',
      label: duplicateToAction?.label ?? '',
      glyph: '',
      overflowOnly: true,
      onAction: duplicateToAction?.onAction,
    },
  ]

  const toMenuItems = (items: readonly ElementAction[]) =>
    items
      .filter((a): a is ElementAction & { onAction: () => void } => a.onAction !== undefined)
      .map(({ key, label, destructive, onAction }) => ({ key, label, destructive, onAction }))

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
        <ActionsMenu actions={toMenuItems(actions)} />
        {dialogs}
      </>
    )
  }

  const iconActions = actions.filter((a) => a.overflowOnly !== true)
  const overflowActions = actions.filter((a) => a.overflowOnly === true)

  // Roving tabindex over the toolbar (W3C APG): the whole toolbar is one tab
  // stop and Arrow/Home/End move between controls. Only enabled buttons take
  // part — a disabled <button> cannot hold focus, so it is skipped rather than
  // being made focusable-but-inert. The overflow trigger is the last stop.
  const enabledIconKeys = iconActions.filter((a) => a.onAction !== undefined).map((a) => a.key)
  const overflowIndex = enabledIconKeys.length
  const hasOverflow = toMenuItems(overflowActions).length > 0
  const stopCount = overflowIndex + (hasOverflow ? 1 : 0)
  // Clamped so a shrinking action set cannot strand the tab stop on a control
  // that no longer renders, which would leave the toolbar unreachable by Tab.
  const activeStop = Math.min(activeIndex, Math.max(0, stopCount - 1))

  function handleToolbarKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    const buttons = Array.from(
      toolbarRef.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? [],
    )
    const current = buttons.findIndex((button) => button === document.activeElement)
    // Focus is on the open overflow menu's container, not a toolbar control —
    // these keys are the menu's to handle, so leave it be.
    if (current === -1) return

    const last = buttons.length - 1
    let next: number
    switch (e.key) {
      case 'ArrowRight':
        next = current >= last ? last : current + 1
        break
      case 'ArrowLeft':
        next = current <= 0 ? 0 : current - 1
        break
      case 'Home':
        next = 0
        break
      case 'End':
        next = last
        break
      default:
        return
    }

    e.preventDefault()
    setActiveIndex(next)
    buttons[next]?.focus()
  }

  return (
    <>
      <div
        ref={toolbarRef}
        className="ssgrid-element-toolbar"
        data-testid="element-toolbar"
        role="toolbar"
        aria-label={t('WeDevelopGrid.ElementActions.TOOLBAR_LABEL', 'Element actions')}
        onKeyDown={handleToolbarKeyDown}
      >
        {iconActions.map((action) => (
          <ToolbarButton
            key={action.key}
            glyph={action.glyph}
            label={action.label}
            onClick={action.onAction}
            disabled={action.onAction === undefined}
            destructive={action.destructive}
            testId={`element-action-${action.key}`}
            tabIndex={enabledIconKeys.indexOf(action.key) === activeStop ? 0 : -1}
          />
        ))}
        <ActionsMenu
          actions={toMenuItems(overflowActions)}
          triggerTabIndex={activeStop === overflowIndex ? 0 : -1}
        />
      </div>
      {dialogs}
    </>
  )
}
