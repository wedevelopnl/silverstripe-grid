import { useCallback, useState } from 'react'
import { t } from '@/i18n'
import { getDefaultViewport, getViewports } from '@/utils/gridAdapter'
import { useGridEditorContext } from './GridEditorContext'
import { useResetGridSettingsOverrides } from './useElementMutations'
import { useViewportOverrideCounts } from './useElementTree'
import { useViewportContext } from './ViewportContext'

interface ResetOverridesState {
  readonly showReset: boolean
  readonly label: string
  readonly affectedCount: number
  readonly isDialogOpen: boolean
  readonly dialogTitle: string
  readonly dialogMessage: string
  readonly onResetClick: () => void
  readonly onConfirm: () => void
  readonly onCancel: () => void
}

export function useResetOverridesAction(): ResetOverridesState {
  const { activeViewport: selectedViewport } = useViewportContext()
  const { pageId, zone } = useGridEditorContext()
  const overrideCounts = useViewportOverrideCounts(pageId, zone)
  const { mutate: resetOverrides } = useResetGridSettingsOverrides(pageId, zone)
  const [isDialogOpen, setDialogOpen] = useState(false)

  const defaultViewport = getDefaultViewport()
  // No selection resolves to the adapter default, which drives "reset all".
  const activeViewport = selectedViewport ?? defaultViewport
  const isDefaultViewport = activeViewport === defaultViewport

  const affectedCount = isDefaultViewport
    ? // biome-ignore lint/suspicious/noUnnecessaryConditions: Record index is typed `number`, but overrideCounts._total is `undefined` when no overrides exist; the ?? 0 fallback is runtime-required (dropping it yields undefined, not 0).
      (overrideCounts._total ?? 0)
    : (overrideCounts[activeViewport] ?? 0)

  const viewportLabel =
    getViewports().find((vp) => vp.key === activeViewport)?.label ?? activeViewport

  const label = isDefaultViewport
    ? t('WeDevelopGrid.useResetOverridesAction.RESET_ALL_LABEL', 'Reset all')
    : t('WeDevelopGrid.useResetOverridesAction.RESET_VIEWPORT_LABEL', 'Reset viewport')

  const dialogTitle = isDefaultViewport
    ? t('WeDevelopGrid.useResetOverridesAction.DIALOG_TITLE_ALL', 'Reset all overrides')
    : t(
        'WeDevelopGrid.useResetOverridesAction.DIALOG_TITLE_VIEWPORT',
        'Reset {viewport} overrides',
        { viewport: viewportLabel },
      )

  const dialogMessage = ((): string => {
    if (isDefaultViewport) {
      return affectedCount === 1
        ? t(
            'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_ALL_ONE',
            'Reset all viewport overrides across {count} column?',
            { count: affectedCount },
          )
        : t(
            'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_ALL_MANY',
            'Reset all viewport overrides across {count} columns?',
            { count: affectedCount },
          )
    }
    return affectedCount === 1
      ? t(
          'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_VIEWPORT_ONE',
          'Reset overrides for {count} column on {viewport}?',
          { count: affectedCount, viewport: viewportLabel },
        )
      : t(
          'WeDevelopGrid.useResetOverridesAction.DIALOG_MESSAGE_VIEWPORT_MANY',
          'Reset overrides for {count} columns on {viewport}?',
          { count: affectedCount, viewport: viewportLabel },
        )
  })()

  const handleResetClick = useCallback(() => {
    setDialogOpen(true)
  }, [])

  const handleCancel = useCallback(() => {
    setDialogOpen(false)
  }, [])

  const handleConfirm = useCallback(() => {
    setDialogOpen(false)
    const params = isDefaultViewport ? { pageId, zone } : { pageId, zone, viewport: activeViewport }
    resetOverrides(params)
  }, [isDefaultViewport, pageId, zone, activeViewport, resetOverrides])

  return {
    showReset: affectedCount > 0,
    label,
    affectedCount,
    isDialogOpen,
    dialogTitle,
    dialogMessage,
    onResetClick: handleResetClick,
    onConfirm: handleConfirm,
    onCancel: handleCancel,
  }
}
