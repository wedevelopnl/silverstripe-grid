import ConfirmDialog from '@/components/ConfirmDialog/ConfirmDialog'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useViewportOverrideCounts } from '@/hooks/useElementTree'
import { useResetOverridesAction } from '@/hooks/useResetOverridesAction'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import { getDefaultViewport, getViewports } from '@/utils/gridAdapter'
import ViewportPicker from './ViewportPicker'

interface ViewportSwitcherProps {
  readonly readonly?: boolean
  /**
   * Archived version being viewed, when the host is the history viewer. The
   * override marks must describe the tree on screen, and the versioned tree
   * lives under its own query key — omitting this would read the draft instead
   * (and fetch it, since that entry need not be cached).
   */
  readonly version?: number
}

/**
 * Viewport switching and override-resetting for the grid editor.
 *
 * Owns the data — which viewport is active, what each one overrides, which
 * scopes can be reset — and the confirmation; {@link ViewportPicker} is the
 * presentation. Kept as a wrapper rather than folded into the picker so the
 * dialog has a host that outlives the popup, and so the editor has one place to
 * mount viewport chrome.
 */
export default function ViewportSwitcher({ readonly = false, version }: ViewportSwitcherProps) {
  const viewports = getViewports()
  const { activeViewport, setActiveViewport } = useViewportContext()
  const { pageId, zone } = useGridEditorContext()
  // Reads the tree query's existing cache entry — no extra fetch. Drives the
  // dot marking which viewports columns actually deviate at.
  const { byViewport } = useViewportOverrideCounts(pageId, zone, version)
  const reset = useResetOverridesAction()

  return (
    <div className="ssgrid-viewport-control" data-testid="viewport-switcher">
      <ViewportPicker
        viewports={viewports}
        // No explicit selection resolves to the adapter default.
        activeViewport={activeViewport ?? getDefaultViewport()}
        defaultViewport={getDefaultViewport()}
        onSelectViewport={setActiveViewport}
        overrideCounts={byViewport}
        resetOptions={readonly ? [] : reset.options}
        onSelectReset={reset.requestReset}
      />
      {reset.dialog !== null && (
        <ConfirmDialog
          isOpen
          title={reset.dialog.title}
          message={reset.dialog.message}
          confirmLabel={t('WeDevelopGrid.ViewportSwitcher.RESET_CONFIRM_LABEL', 'Reset')}
          onConfirm={reset.onConfirm}
          onCancel={reset.onCancel}
          destructive
        />
      )}
    </div>
  )
}
