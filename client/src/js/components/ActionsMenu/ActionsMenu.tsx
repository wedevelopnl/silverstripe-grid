// biome-ignore-all lint/suspicious/noUnnecessaryConditions: biome's type inference treats the Enter/' ' comparisons as unreachable, but they handle real KeyboardEvent.key values at runtime.
import { useRovingPopup } from '@/hooks/useRovingPopup'
import { t } from '@/i18n'

export interface ActionItem {
  readonly key: string
  readonly label: string
  readonly destructive?: boolean
  readonly onAction: () => void
}

interface ActionsMenuProps {
  readonly actions: readonly ActionItem[]
  readonly testId?: string
  /**
   * Tab-order position of the trigger. Set by a parent that owns a roving
   * tabindex (the element toolbar); left undefined the trigger is a tab stop
   * of its own, which is right for a standalone menu.
   */
  readonly triggerTabIndex?: number
}

export default function ActionsMenu({
  actions,
  testId = 'actions-menu',
  triggerTabIndex,
}: ActionsMenuProps) {
  const popup = useRovingPopup({ itemCount: actions.length })

  // preventDefault is required because this button may be nested inside a
  // clickable ancestor (ElementCard's <a href>). React synthetic
  // stopPropagation only blocks other React handlers from firing; the
  // browser's default anchor navigation is cancelled only by preventDefault
  // on the underlying click event.
  function handleTriggerClick(e: React.MouseEvent) {
    e.preventDefault()
    e.stopPropagation()
    popup.toggle()
  }

  function handleItemClick(e: React.MouseEvent, onAction: () => void) {
    e.preventDefault()
    e.stopPropagation()
    onAction()
    popup.close()
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    if (popup.handleNavigationKeyDown(e)) return

    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault()
      e.stopPropagation()
      const current = actions[popup.activeIndex]
      // Stryker disable next-line ConditionalExpression: Equivalent — actions is non-empty (the component returns null otherwise) and the hook clamps activeIndex into range, so current is always defined
      if (current) {
        current.onAction()
        popup.close()
      }
    }
  }

  if (actions.length === 0) {
    return null
  }

  const menuId = `${testId}-menu`

  return (
    <div ref={popup.wrapperRef} className="ssgrid-actions-menu">
      <button
        ref={popup.triggerRef}
        type="button"
        className="ssgrid-icon-button"
        data-testid="actions-menu-trigger"
        tabIndex={triggerTabIndex}
        aria-haspopup="menu"
        aria-expanded={popup.isOpen}
        aria-controls={popup.isOpen ? menuId : undefined}
        aria-label={t('WeDevelopGrid.ActionsMenu.TRIGGER_LABEL', 'Actions')}
        onClick={handleTriggerClick}
      >
        <span className="ssgrid-icon-button__glyph font-icon-dot-3" aria-hidden="true" />
      </button>
      {popup.isOpen && (
        <div
          id={menuId}
          ref={popup.popupRef}
          className="ssgrid-actions-menu__menu"
          role="menu"
          tabIndex={-1}
          aria-activedescendant={popup.getItemId(popup.activeIndex)}
          data-testid={`${testId}-dropdown`}
          onKeyDown={handleMenuKeyDown}
        >
          {actions.map((action, index) => (
            // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant pattern per W3C APG); menuitems are not focusable themselves
            <div
              key={action.key}
              id={popup.getItemId(index)}
              className="ssgrid-actions-menu__item"
              role="menuitem"
              tabIndex={index === popup.activeIndex ? 0 : -1}
              data-destructive={action.destructive ? 'true' : undefined}
              onClick={(e) => handleItemClick(e, action.onAction)}
            >
              {action.label}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
