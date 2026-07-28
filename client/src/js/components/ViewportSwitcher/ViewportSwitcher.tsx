import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog'
import { useResetOverridesAction } from '@/hooks/useResetOverridesAction'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import { getViewports } from '@/utils/gridAdapter'
import { getViewportIcon } from './viewportIcon'

interface ViewportSwitcherProps {
  readonly readonly?: boolean
}

export default function ViewportSwitcher({ readonly = false }: ViewportSwitcherProps) {
  const viewports = getViewports()
  const { activeViewport, setActiveViewport } = useViewportContext()
  const reset = useResetOverridesAction()

  return (
    <>
      <div
        className="ssgrid-viewport-switcher"
        role="toolbar"
        aria-label={t('WeDevelopGrid.ViewportSwitcher.GROUP_LABEL', 'Viewport size')}
        data-testid="viewport-switcher"
      >
        {viewports.map((viewport, index) => {
          const isActive = viewport.key === activeViewport
          // The Figma toolbar labels each viewport with its *upper* boundary
          // ("<768") — i.e. the next viewport's min-width. The largest viewport
          // has no upper bound and is left blank.
          const next = viewports[index + 1]

          return (
            <button
              key={viewport.key}
              type="button"
              className="ssgrid-viewport-switcher__button"
              data-testid={`viewport-button-${viewport.key}`}
              aria-pressed={isActive}
              aria-disabled={isActive || undefined}
              onClick={() => {
                if (!isActive) {
                  setActiveViewport(viewport.key)
                }
              }}
            >
              <i
                className={`ssgrid-viewport-switcher__icon ${getViewportIcon(viewport.minWidth)}`}
                aria-hidden="true"
              />
              <span className="ssgrid-viewport-switcher__label">{viewport.label}</span>
              {next !== undefined && (
                <span className="ssgrid-viewport-switcher__range">{`<${next.minWidth}`}</span>
              )}
            </button>
          )
        })}
      </div>
      {!readonly && reset.showReset && (
        <button
          type="button"
          className="ssgrid-viewport-switcher__reset"
          data-testid="reset-overrides-button"
          onClick={reset.onResetClick}
        >
          {reset.label}
        </button>
      )}
      {!readonly && reset.isDialogOpen && (
        <ConfirmDialog
          isOpen={reset.isDialogOpen}
          title={reset.dialogTitle}
          message={reset.dialogMessage}
          confirmLabel={t('WeDevelopGrid.ViewportSwitcher.RESET_CONFIRM_LABEL', 'Reset')}
          onConfirm={reset.onConfirm}
          onCancel={reset.onCancel}
          destructive
        />
      )}
    </>
  )
}
