import { useCallback, useState } from 'react'
import { t } from '@/i18n'
import { getViewports } from '@/utils/gridAdapter'
import { useGridEditorContext } from './GridEditorContext'
import { useResetGridSettingsOverrides } from './useElementMutations'
import { useViewportOverrideCounts } from './useElementTree'

export interface ResetScopeOption {
  /** Viewport to clear, or `null` for every viewport. */
  readonly viewport: string | null
  /** Viewport name, or the "all viewports" wording. */
  readonly label: string
  /** Columns this reset would clear. */
  readonly count: number
}

interface ResetDialog {
  readonly title: string
  readonly message: string
}

interface ResetOverridesState {
  /**
   * One entry per viewport that something actually overrides, in adapter order,
   * plus an "all viewports" entry. Empty when the page carries no overrides.
   */
  readonly options: readonly ResetScopeOption[]
  /** Non-null only while a scope is awaiting confirmation. */
  readonly dialog: ResetDialog | null
  readonly requestReset: (option: ResetScopeOption) => void
  readonly onConfirm: () => void
  readonly onCancel: () => void
}

/**
 * The reset-overrides actions, one per scope the page actually has work in.
 *
 * Scope is chosen explicitly by the caller rather than inferred from the
 * selected viewport. The previous shape had one button whose meaning flipped
 * between "this viewport" and "every viewport" depending on whether the active
 * tab happened to be the adapter's default — which put "reset everything"
 * behind a different tab on every adapter (`md` on Bootstrap, `sm` on Tailwind,
 * `desktop` on Bulma) and left no way to reach it from anywhere else.
 */
export function useResetOverridesAction(): ResetOverridesState {
  const { pageId, zone } = useGridEditorContext()
  const { total, byViewport } = useViewportOverrideCounts(pageId, zone)
  const { mutate: resetOverrides } = useResetGridSettingsOverrides(pageId, zone)
  const [pending, setPending] = useState<ResetScopeOption | null>(null)

  const perViewport = getViewports()
    .map((viewport) => ({
      viewport: viewport.key,
      label: viewport.label,
      count: byViewport[viewport.key] ?? 0,
    }))
    .filter((option) => option.count > 0)

  // "All viewports" is offered only when it differs from the single entry above
  // it — with one overridden viewport the two would clear exactly the same
  // columns, and a menu that lists the same action twice reads as a mistake.
  const options: readonly ResetScopeOption[] =
    perViewport.length > 1
      ? [
          ...perViewport,
          {
            viewport: null,
            label: t('WeDevelopGrid.useResetOverridesAction.ALL_VIEWPORTS', 'All viewports'),
            count: total,
          },
        ]
      : perViewport

  const dialog: ResetDialog | null =
    pending === null
      ? null
      : {
          title:
            pending.viewport === null
              ? t('WeDevelopGrid.useResetOverridesAction.DIALOG_TITLE_ALL', 'Reset all overrides')
              : t(
                  'WeDevelopGrid.useResetOverridesAction.DIALOG_TITLE_VIEWPORT',
                  'Reset {viewport} overrides',
                  { viewport: pending.label },
                ),
          message: buildDialogMessage(pending),
        }

  const requestReset = useCallback((option: ResetScopeOption) => {
    setPending(option)
  }, [])

  const onCancel = useCallback(() => {
    setPending(null)
  }, [])

  const onConfirm = useCallback(() => {
    if (pending === null) {
      return
    }
    setPending(null)
    resetOverrides(
      pending.viewport === null ? { pageId, zone } : { pageId, zone, viewport: pending.viewport },
    )
  }, [pending, pageId, zone, resetOverrides])

  return { options, dialog, requestReset, onConfirm, onCancel }
}

function buildDialogMessage(pending: ResetScopeOption): string {
  if (pending.viewport === null) {
    return pending.count === 1
      ? t(
          'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_ALL_ONE',
          'Reset all viewport overrides across {count} column?',
          { count: pending.count },
        )
      : t(
          'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_ALL_MANY',
          'Reset all viewport overrides across {count} columns?',
          { count: pending.count },
        )
  }

  return pending.count === 1
    ? t(
        'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_VIEWPORT_ONE',
        'Reset overrides for {count} column on {viewport}?',
        { count: pending.count, viewport: pending.label },
      )
    : t(
        'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_VIEWPORT_MANY',
        'Reset overrides for {count} columns on {viewport}?',
        { count: pending.count, viewport: pending.label },
      )
}
