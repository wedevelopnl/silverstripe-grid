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
  /** Offered but inert, because the scope has nothing to clear. */
  readonly disabled: boolean
  /**
   * The count with its unit ("2 columns"), for the row's own count column.
   * Carries the noun rather than showing a bare number, because the scopes are
   * overlapping sets of columns rather than addends — one column overridden at
   * three viewports counts once here and once in each of those viewport rows,
   * so a column of naked numerals reads as a sum that does not add up.
   */
  readonly countLabel: string
  /**
   * The scope as a sentence ("Reset Large, 1 column"), for the accessible name
   * of a menu item whose visible form is a name and a bare number in two
   * columns — which would otherwise announce as "Large 1". Lives here so the
   * standalone menu and the collapsed picker cannot word it differently.
   */
  readonly actionLabel: string
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
 * Scope is chosen explicitly by the caller, never inferred from the selected
 * viewport: the active viewport says which layout is being edited, not which
 * overrides the author means to clear.
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

  // "All viewports" is always offered, and inert only when the page carries no
  // overrides at all. It holds a fixed position so the destructive scope never
  // moves under the pointer, and it is the only scope that can reach overrides
  // stored against viewports the current adapter no longer declares — switching
  // adapter orphans them, and no per-viewport row can name them because the row
  // list is built from the adapter's own viewports.
  const scopes = [
    ...perViewport,
    {
      viewport: null,
      label: t('WeDevelopGrid.useResetOverridesAction.ALL_VIEWPORTS', 'All viewports'),
      count: total,
    },
  ]

  const options: readonly ResetScopeOption[] = scopes.map((scope) => ({
    ...scope,
    disabled: scope.count === 0,
    countLabel:
      scope.count === 1
        ? t('WeDevelopGrid.useResetOverridesAction.COUNT_ONE', '{count} column', {
            count: scope.count,
          })
        : t('WeDevelopGrid.useResetOverridesAction.COUNT_MANY', '{count} columns', {
            count: scope.count,
          }),
    actionLabel:
      scope.count === 1
        ? t(
            'WeDevelopGrid.useResetOverridesAction.ACTION_LABEL_ONE',
            'Reset {label}, {count} column',
            { label: scope.label, count: scope.count },
          )
        : t(
            'WeDevelopGrid.useResetOverridesAction.ACTION_LABEL_MANY',
            'Reset {label}, {count} columns',
            { label: scope.label, count: scope.count },
          ),
  }))

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
