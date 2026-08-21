// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's inference treats the Enter/' ' comparisons as unreachable, but they handle real KeyboardEvent.key values at runtime.
import { useRovingPopup } from '@/hooks/useRovingPopup'

interface PlacementMenuProps {
  /** Accessible name for the caret; the caret itself shows only a glyph. */
  readonly triggerLabel: string
  /** The one alternative route offered here, already translated. */
  readonly itemLabel: string
  readonly onSelect: () => void
  /** `strip` rides a full-width dashed add strip; `chip` rides a solid brand square. */
  readonly variant: 'strip' | 'chip'
  readonly testId: string
}

/**
 * The caret half of a split add control: a subordinate trigger opening the
 * routes that are not "create a new one of the obvious class".
 *
 * Deliberately not `ActionsMenu` — that component hardcodes a kebab trigger and
 * an "Actions" label. Both share `useRovingPopup`, which owns open/close,
 * outside-mousedown dismissal, Escape-with-focus-return and the roving
 * aria-activedescendant pattern.
 *
 * One item, because there is one alternative route. A second one turns
 * itemLabel/onSelect into a list; nothing else here changes.
 */
export default function PlacementMenu({
  triggerLabel,
  itemLabel,
  onSelect,
  variant,
  testId,
}: PlacementMenuProps) {
  const popup = useRovingPopup({ itemCount: 1 })

  // preventDefault/stopPropagation: this sits inside full-width click targets
  // (the add strip) whose own handler would otherwise create an element.
  function handleTriggerClick(e: React.MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    popup.toggle()
  }

  function handleItemClick(e: React.MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    onSelect()
    popup.close()
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      e.stopPropagation()
      onSelect()
      popup.close()
    }
  }

  const menuId = `${testId}-menu`

  return (
    <div
      ref={popup.wrapperRef}
      className="ssgrid-placement-menu ssgrid-popover-anchor"
      data-variant={variant}
      data-testid={testId}
    >
      <button
        ref={popup.triggerRef}
        type="button"
        className="ssgrid-placement-menu-trigger ssgrid-focus-ring"
        data-testid={`${testId}-trigger`}
        aria-haspopup="menu"
        aria-expanded={popup.isOpen}
        aria-controls={popup.isOpen ? menuId : undefined}
        aria-label={triggerLabel}
        onClick={handleTriggerClick}
      >
        <span
          className="ssgrid-placement-menu-caret ssgrid-glyph font-icon-down-open"
          aria-hidden="true"
        />
      </button>
      {popup.isOpen && (
        <div
          id={menuId}
          ref={popup.popupRef}
          className="ssgrid-placement-menu-menu ssgrid-popover-surface"
          role="menu"
          tabIndex={-1}
          aria-activedescendant={popup.getItemId(0)}
          data-testid={`${testId}-dropdown`}
          onKeyDown={handleMenuKeyDown}
        >
          {/* biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant per W3C APG); menuitems are not focusable themselves */}
          <div
            id={popup.getItemId(0)}
            className="ssgrid-placement-menu-item ssgrid-popover-item"
            role="menuitem"
            tabIndex={0}
            onClick={handleItemClick}
          >
            {itemLabel}
          </div>
        </div>
      )}
    </div>
  )
}
