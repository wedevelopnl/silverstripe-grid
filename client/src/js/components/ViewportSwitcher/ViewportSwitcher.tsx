import ViewportResetMenu from '@/components/ViewportResetMenu/ViewportResetMenu'
import { useGridEditorContext } from '@/hooks/GridEditorContext'
import { useViewportOverrideCounts } from '@/hooks/useElementTree'
import { useViewportContext } from '@/hooks/ViewportContext'
import { t } from '@/i18n'
import { getViewports } from '@/utils/gridAdapter'
import { getViewportIcon } from './viewportIcon'

interface ViewportSwitcherProps {
  readonly readonly?: boolean
  /**
   * Archived version being viewed, when the host is the history viewer. The
   * dots must describe the tree on screen, and the versioned tree lives under
   * its own query key — omitting this would read the draft instead (and fetch
   * it, since that entry need not be cached).
   */
  readonly version?: number
}

export default function ViewportSwitcher({ readonly = false, version }: ViewportSwitcherProps) {
  const viewports = getViewports()
  const { activeViewport, setActiveViewport } = useViewportContext()
  const { pageId, zone } = useGridEditorContext()
  // Reads the tree query's existing cache entry — no extra fetch. Drives the
  // per-tab dot marking which viewports columns actually deviate at.
  const { byViewport } = useViewportOverrideCounts(pageId, zone, version)

  return (
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
        const overrideCount = byViewport[viewport.key] ?? 0

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
            {overrideCount > 0 && (
              <>
                <span className="ssgrid-viewport-switcher__override-dot" aria-hidden="true" />
                <span className="ssgrid-viewport-switcher__override-label">
                  {overrideCount === 1
                    ? t(
                        'WeDevelopGrid.ViewportSwitcher.HAS_OVERRIDES_ONE',
                        '{count} column overrides this viewport',
                        { count: overrideCount },
                      )
                    : t(
                        'WeDevelopGrid.ViewportSwitcher.HAS_OVERRIDES_MANY',
                        '{count} columns override this viewport',
                        { count: overrideCount },
                      )}
                </span>
              </>
            )}
          </button>
        )
      })}
      {!readonly && <ViewportResetMenu />}
    </div>
  )
}
