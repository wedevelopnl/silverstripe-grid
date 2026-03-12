import { useCallback, useEffect, useRef, useState } from 'react';
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
  const wrapperRef = useRef<HTMLDivElement>(null);

  const close = useCallback(() => setIsOpen(false), []);

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
        e.stopPropagation();
        close();
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

  function handleItemKeyDown(e: React.KeyboardEvent, onAction: () => void) {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      e.stopPropagation();
      onAction();
      close();
    }
  }

  if (actions.length === 0) {
    return null;
  }

  const menuId = `${testId}-menu`;

  return (
    <div ref={wrapperRef} className="actions-menu">
      <button
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
        <ul
          id={menuId}
          className="actions-menu__dropdown"
          role="menu"
          data-testid={`${testId}-dropdown`}
        >
          {actions.map((action) => (
            <li
              key={action.key}
              className={`actions-menu__item${action.destructive ? ' actions-menu__item--destructive' : ''}`}
              role="menuitem"
              tabIndex={0}
              onClick={(e) => handleItemClick(e, action.onAction)}
              onKeyDown={(e) => handleItemKeyDown(e, action.onAction)}
            >
              {action.label}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
