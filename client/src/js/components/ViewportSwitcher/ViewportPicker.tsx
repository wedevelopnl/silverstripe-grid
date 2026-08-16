// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's type inference treats the Enter/' ' comparisons as unreachable, but they handle real KeyboardEvent.key values at runtime.
import type { ResetScopeOption } from '@/hooks/useResetOverridesAction'
import { useRovingPopup } from '@/hooks/useRovingPopup'
import { t } from '@/i18n'
import type { ViewportConfig } from '@/types/adapter'
import { getViewportIcon } from './viewportIcon'

interface ViewportPickerProps {
  readonly viewports: readonly ViewportConfig[]
  readonly activeViewport: string
  /**
   * The adapter's base viewport. Editing here changes the layout everywhere;
   * editing anywhere else records an override against this one, so which
   * viewport it is decides what a width change actually means.
   */
  readonly defaultViewport: string
  readonly onSelectViewport: (key: string) => void
  /** Columns overriding each viewport, keyed by viewport key. */
  readonly overrideCounts: Readonly<Record<string, number>>
  /** Empty in readonly mode, or when nothing is overridden. */
  readonly resetOptions: readonly ResetScopeOption[]
  readonly onSelectReset: (option: ResetScopeOption) => void
}

/** A menu row: either a viewport to switch to, or a scope to reset. */
type Entry =
  | { readonly kind: 'viewport'; readonly viewport: ViewportConfig; readonly overrides: number }
  | { readonly kind: 'reset'; readonly option: ResetScopeOption }

/**
 * The viewport control: one button naming the current viewport, one menu
 * holding every viewport and every reset scope.
 *
 * One row at any width, for any adapter, however many viewports it declares
 * and however long their labels run.
 *
 * Switching and resetting share the menu rather than sitting behind two
 * triggers, because they are about the same thing. They stay separate *groups*
 * so the radio semantics of picking a viewport are not blurred into the
 * one-shot semantics of clearing one.
 */
export default function ViewportPicker({
  viewports,
  activeViewport,
  defaultViewport,
  onSelectViewport,
  overrideCounts,
  resetOptions,
  onSelectReset,
}: ViewportPickerProps) {
  const entries: Entry[] = [
    ...viewports.map(
      (viewport): Entry => ({
        kind: 'viewport',
        viewport,
        overrides: overrideCounts[viewport.key] ?? 0,
      }),
    ),
    ...resetOptions.map((option): Entry => ({ kind: 'reset', option })),
  ]

  const activeIndex = Math.max(
    0,
    viewports.findIndex((viewport) => viewport.key === activeViewport),
  )
  // Open on the current viewport rather than the first row, so the menu starts
  // where the author is.
  const popup = useRovingPopup({ itemCount: entries.length, seedIndex: activeIndex })

  const current = viewports[activeIndex]
  const currentIndex = viewports.indexOf(current)
  const nextUp = viewports[currentIndex + 1]
  const anyOverrides = Object.values(overrideCounts).some((count) => count > 0)

  function activate(entry: Entry) {
    popup.close()
    if (entry.kind === 'viewport') {
      if (entry.viewport.key !== activeViewport) {
        onSelectViewport(entry.viewport.key)
      }
      return
    }
    onSelectReset(entry.option)
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      e.stopPropagation()
      const entry = entries[popup.activeIndex]
      // Stryker disable next-line ConditionalExpression: Equivalent — entries always holds one row per viewport (adapters expose at least one) and the hook clamps activeIndex into range, so entry is always defined
      if (entry) {
        activate(entry)
      }
    }
  }

  const menuId = 'viewport-picker-menu'

  return (
    <div ref={popup.wrapperRef} className="ssgrid-viewport-picker">
      <button
        ref={popup.triggerRef}
        type="button"
        className="ssgrid-viewport-picker__trigger"
        data-testid="viewport-picker-trigger"
        data-viewport={current.key}
        aria-haspopup="menu"
        aria-expanded={popup.isOpen}
        aria-controls={popup.isOpen ? menuId : undefined}
        aria-label={t('WeDevelopGrid.ViewportPicker.TRIGGER_LABEL', 'Viewport: {label}', {
          label: current.label,
        })}
        onClick={popup.toggle}
      >
        <i
          className={`ssgrid-viewport-picker__icon ${getViewportIcon(current.minWidth)}`}
          aria-hidden="true"
        />
        <span className="ssgrid-viewport-picker__label">{current.label}</span>
        {nextUp !== undefined && (
          <span className="ssgrid-viewport-picker__range">{`<${nextUp.minWidth}`}</span>
        )}
        {current.key === defaultViewport && (
          <span className="ssgrid-viewport-picker__default">
            {t('WeDevelopGrid.ViewportPicker.DEFAULT', 'Default')}
          </span>
        )}
        {anyOverrides && (
          <span className="ssgrid-viewport-picker__override-dot" aria-hidden="true" />
        )}
        <i className="ssgrid-viewport-picker__caret font-icon-down-open" aria-hidden="true" />
      </button>
      {popup.isOpen && (
        <div
          id={menuId}
          ref={popup.popupRef}
          className="ssgrid-viewport-picker__menu"
          role="menu"
          tabIndex={-1}
          aria-activedescendant={popup.getItemId(popup.activeIndex)}
          data-testid="viewport-picker-dropdown"
          onKeyDown={handleMenuKeyDown}
        >
          {/* biome-ignore lint/a11y/useSemanticElements: the W3C APG menu pattern groups menu items with role='group'; a fieldset inside role='menu' would import form semantics and a legend requirement into a menu */}
          <div
            role="group"
            aria-label={t('WeDevelopGrid.ViewportPicker.GROUP_VIEWPORT', 'Viewport size')}
          >
            {entries.map((entry, index) =>
              entry.kind !== 'viewport' ? null : (
                // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant pattern per W3C APG); menuitems are not focusable themselves
                <div
                  key={entry.viewport.key}
                  id={popup.getItemId(index)}
                  className="ssgrid-viewport-picker__item"
                  role="menuitemradio"
                  aria-checked={entry.viewport.key === activeViewport}
                  tabIndex={index === popup.activeIndex ? 0 : -1}
                  data-testid={`viewport-picker-option-${entry.viewport.key}`}
                  onClick={() => activate(entry)}
                >
                  <i
                    className={`ssgrid-viewport-picker__icon ${getViewportIcon(entry.viewport.minWidth)}`}
                    aria-hidden="true"
                  />
                  <span className="ssgrid-viewport-picker__item-label">{entry.viewport.label}</span>
                  {entry.viewport.key === defaultViewport && (
                    <span className="ssgrid-viewport-picker__default">
                      {t('WeDevelopGrid.ViewportPicker.DEFAULT', 'Default')}
                    </span>
                  )}
                  {entry.overrides > 0 && (
                    <>
                      <span className="ssgrid-viewport-picker__override-dot" aria-hidden="true" />
                      <span className="ssgrid-viewport-picker__override-label">
                        {entry.overrides === 1
                          ? t(
                              'WeDevelopGrid.ViewportPicker.HAS_OVERRIDES_ONE',
                              '{count} column overrides this viewport',
                              { count: entry.overrides },
                            )
                          : t(
                              'WeDevelopGrid.ViewportPicker.HAS_OVERRIDES_MANY',
                              '{count} columns override this viewport',
                              { count: entry.overrides },
                            )}
                      </span>
                    </>
                  )}
                </div>
              ),
            )}
          </div>
          {resetOptions.length > 0 && (
            // biome-ignore lint/a11y/useSemanticElements: the W3C APG menu pattern groups menu items with role="group"; a fieldset inside role="menu" would import form semantics and a legend requirement into a menu
            <div
              role="group"
              className="ssgrid-viewport-picker__group"
              aria-label={t('WeDevelopGrid.ViewportPicker.GROUP_RESET', 'Reset overrides')}
            >
              {/* Visible heading, because a reset row and the viewport row above
                  it read almost identically ("Extra small" either way) — the
                  divider and the icon alone do not say which one clears. Hidden
                  from assistive tech, which already gets the group's label. */}
              <div className="ssgrid-viewport-picker__group-heading" aria-hidden="true">
                {t('WeDevelopGrid.ViewportPicker.GROUP_RESET', 'Reset overrides')}
              </div>
              {entries.map((entry, index) =>
                entry.kind !== 'reset' ? null : (
                  // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant pattern per W3C APG); menuitems are not focusable themselves
                  <div
                    key={entry.option.viewport ?? ' all'}
                    id={popup.getItemId(index)}
                    className="ssgrid-viewport-picker__item ssgrid-viewport-picker__item--reset"
                    role="menuitem"
                    tabIndex={index === popup.activeIndex ? 0 : -1}
                    aria-label={entry.option.actionLabel}
                    data-scope={entry.option.viewport ?? 'all'}
                    onClick={() => activate(entry)}
                  >
                    <i className="font-icon-sync" aria-hidden="true" />
                    <span className="ssgrid-viewport-picker__item-label">{entry.option.label}</span>
                    <span className="ssgrid-viewport-picker__item-count">{entry.option.count}</span>
                  </div>
                ),
              )}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
