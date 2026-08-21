// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's inference treats the Enter/' ' comparisons as unreachable, but they handle real KeyboardEvent.key values at runtime.
import { useRovingPopup } from '@/hooks/useRovingPopup'

export interface PlacementMenuItem {
  /** Stable React key; also the item's `data-route` for tests and styling. */
  readonly key: string
  /** Already translated. */
  readonly label: string
  readonly onSelect: () => void
}

interface PlacementMenuProps {
  /** Accessible name for the caret; the caret itself shows only a glyph. */
  readonly triggerLabel: string
  /** The alternative routes offered here, in display order. */
  readonly items: readonly PlacementMenuItem[]
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
 */
export default function PlacementMenu({
  triggerLabel,
  items,
  variant,
  testId,
}: PlacementMenuProps) {
  const popup = useRovingPopup({ itemCount: items.length })

  // preventDefault/stopPropagation: this sits inside full-width click targets
  // (the add strip) whose own handler would otherwise create an element.
  function handleTriggerClick(e: React.MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    popup.toggle()
  }

  function select(index: number) {
    items[index]?.onSelect()
    popup.close()
  }

  function handleItemClick(e: React.MouseEvent, index: number) {
    e.preventDefault()
    e.stopPropagation()
    select(index)
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      e.stopPropagation()
      select(popup.activeIndex)
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
          aria-activedescendant={popup.getItemId(popup.activeIndex)}
          data-testid={`${testId}-dropdown`}
          onKeyDown={handleMenuKeyDown}
        >
          {items.map((item, index) => (
            // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant per W3C APG); menuitems are not focusable themselves
            <div
              key={item.key}
              id={popup.getItemId(index)}
              className="ssgrid-placement-menu-item ssgrid-popover-item"
              role="menuitem"
              tabIndex={index === popup.activeIndex ? 0 : -1}
              data-route={item.key}
              onClick={(e) => handleItemClick(e, index)}
            >
              {item.label}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
