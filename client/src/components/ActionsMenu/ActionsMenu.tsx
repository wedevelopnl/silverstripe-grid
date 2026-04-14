import { useCallback, useEffect, useId, useRef, useState } from 'react';
import './ActionsMenu.scss';

export interface ActionItem {
  readonly key: string;
  readonly label: string;
  readonly destructive?: boolean;
  readonly onAction: () => void;
}

interface ActionsMenuProps {
  readonly actions: readonly ActionItem[];
  readonly testId?: string;
}

export default function ActionsMenu({ actions, testId = 'actions-menu' }: ActionsMenuProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const wrapperRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const menuRef = useRef<HTMLDivElement>(null);
  // Stable DOM id prefix so aria-activedescendant references a real element id.
  const itemIdPrefix = useId();

  const getItemId = useCallback((index: number) => `${itemIdPrefix}item-${index}`, [itemIdPrefix]);

  const close = useCallback(() => setIsOpen(false), []);

  // Reset to first item and move focus to the menu container each time it opens
  // so Arrow keys drive the aria-activedescendant roving pattern.
  useEffect(() => {
    if (!isOpen) return;
    setActiveIndex(0);
    menuRef.current?.focus();
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    function handleMouseDown(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        close();
      }
    }

    document.addEventListener('mousedown', handleMouseDown);
    return () => document.removeEventListener('mousedown', handleMouseDown);
  }, [isOpen, close]);

  useEffect(() => {
    if (!isOpen) return;

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        // Stryker disable next-line all: stopPropagation prevents bubble to parent menus, not observable via RTL
        e.stopPropagation();
        close();
        triggerRef.current?.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, close]);

  function handleTriggerClick(e: React.MouseEvent) {
    e.stopPropagation();
    setIsOpen((prev) => !prev);
  }

  function handleItemClick(e: React.MouseEvent, onAction: () => void) {
    e.stopPropagation();
    onAction();
    close();
  }

  function handleMenuKeyDown(e: React.KeyboardEvent<HTMLDivElement>) {
    const last = actions.length - 1;
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        setActiveIndex((i) => (i >= last ? last : i + 1));
        return;
      case 'ArrowUp':
        e.preventDefault();
        setActiveIndex((i) => (i <= 0 ? 0 : i - 1));
        return;
      case 'Home':
        e.preventDefault();
        setActiveIndex(0);
        return;
      case 'End':
        e.preventDefault();
        setActiveIndex(last);
        return;
      case 'Enter':
      case ' ': {
        e.preventDefault();
        e.stopPropagation();
        const current = actions[activeIndex];
        if (current) {
          current.onAction();
          close();
        }
        return;
      }
      default:
        return;
    }
  }

  if (actions.length === 0) {
    return null;
  }

  const menuId = `${testId}-menu`;

  return (
    <div ref={wrapperRef} className="actions-menu">
      <button
        ref={triggerRef}
        type="button"
        className="actions-menu__trigger"
        data-testid="actions-menu-trigger"
        aria-haspopup="menu"
        aria-expanded={isOpen}
        aria-controls={isOpen ? menuId : undefined}
        aria-label="Actions"
        onClick={handleTriggerClick}
      >
        <span className="actions-menu__dots" aria-hidden="true" />
      </button>
      {isOpen && (
        <div
          id={menuId}
          ref={menuRef}
          className="actions-menu__dropdown"
          role="menu"
          tabIndex={-1}
          aria-activedescendant={getItemId(activeIndex)}
          data-testid={`${testId}-dropdown`}
          onKeyDown={handleMenuKeyDown}
        >
          {actions.map((action, index) => (
            // biome-ignore lint/a11y/useKeyWithClickEvents: keyboard handling lives on the menu (aria-activedescendant pattern per W3C APG); menuitems are not focusable themselves
            <div
              key={action.key}
              id={getItemId(index)}
              className={`actions-menu__item${action.destructive ? ' actions-menu__item--destructive' : ''}`}
              role="menuitem"
              tabIndex={index === activeIndex ? 0 : -1}
              onClick={(e) => handleItemClick(e, action.onAction)}
            >
              {action.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
