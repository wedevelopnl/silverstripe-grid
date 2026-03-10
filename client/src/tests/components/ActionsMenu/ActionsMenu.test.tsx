import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ActionsMenu from '@/components/ActionsMenu/ActionsMenu';
import type { ActionItem } from '@/components/ActionsMenu/ActionsMenu';

describe('ActionsMenu', () => {
  function makeAction(overrides: Partial<ActionItem> = {}): ActionItem {
    return {
      key: 'archive',
      label: 'Archive',
      destructive: true,
      onAction: vi.fn(),
      ...overrides,
    };
  }

  it('returns null when actions array is empty', () => {
    const { container } = render(<ActionsMenu actions={[]} />);
    expect(container.innerHTML).toBe('');
  });

  it('renders the kebab trigger button', () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  it('does not show dropdown by default', () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('shows dropdown when trigger is clicked', async () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    await userEvent.click(screen.getByTestId('actions-menu-trigger'));
    expect(screen.getByRole('menu')).toBeDefined();
  });

  it('renders menu items from actions', async () => {
    const actions = [
      makeAction({ key: 'archive', label: 'Archive' }),
      makeAction({ key: 'publish', label: 'Publish', destructive: false }),
    ];
    render(<ActionsMenu actions={actions} />);
    await userEvent.click(screen.getByTestId('actions-menu-trigger'));

    expect(screen.getByRole('menuitem', { name: 'Archive' })).toBeDefined();
    expect(screen.getByRole('menuitem', { name: 'Publish' })).toBeDefined();
  });

  it('calls onAction and closes dropdown when item is clicked', async () => {
    const onAction = vi.fn();
    render(<ActionsMenu actions={[makeAction({ onAction })]} />);

    await userEvent.click(screen.getByTestId('actions-menu-trigger'));
    await userEvent.click(screen.getByRole('menuitem', { name: 'Archive' }));

    expect(onAction).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('closes dropdown on Escape key', async () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    await userEvent.click(screen.getByTestId('actions-menu-trigger'));
    expect(screen.getByRole('menu')).toBeDefined();

    await userEvent.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('toggles dropdown on repeated trigger clicks', async () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    const trigger = screen.getByTestId('actions-menu-trigger');

    await userEvent.click(trigger);
    expect(screen.getByRole('menu')).toBeDefined();

    await userEvent.click(trigger);
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('applies destructive class to destructive menu items', async () => {
    render(<ActionsMenu actions={[makeAction({ destructive: true })]} />);
    await userEvent.click(screen.getByTestId('actions-menu-trigger'));

    const item = screen.getByRole('menuitem', { name: 'Archive' });
    expect(item.classList.contains('actions-menu__item--destructive')).toBe(true);
  });

  it('calls onAction when Enter is pressed on a menu item', async () => {
    const onAction = vi.fn();
    render(<ActionsMenu actions={[makeAction({ onAction })]} />);

    await userEvent.click(screen.getByTestId('actions-menu-trigger'));
    const item = screen.getByRole('menuitem', { name: 'Archive' });
    item.focus();
    await userEvent.keyboard('{Enter}');

    expect(onAction).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('calls onAction when Space is pressed on a menu item', async () => {
    const onAction = vi.fn();
    render(<ActionsMenu actions={[makeAction({ onAction })]} />);

    await userEvent.click(screen.getByTestId('actions-menu-trigger'));
    const item = screen.getByRole('menuitem', { name: 'Archive' });
    item.focus();
    await userEvent.keyboard(' ');

    expect(onAction).toHaveBeenCalledOnce();
    expect(screen.queryByRole('menu')).toBeNull();
  });

  it('does not apply destructive class to non-destructive menu items', async () => {
    render(<ActionsMenu actions={[makeAction({ destructive: false, label: 'Duplicate' })]} />);
    await userEvent.click(screen.getByTestId('actions-menu-trigger'));

    const item = screen.getByRole('menuitem', { name: 'Duplicate' });
    expect(item.classList.contains('actions-menu__item--destructive')).toBe(false);
  });

  it('sets aria-haspopup on trigger', () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    expect(screen.getByTestId('actions-menu-trigger').getAttribute('aria-haspopup')).toBe('menu');
  });

  it('sets aria-expanded based on open state', async () => {
    render(<ActionsMenu actions={[makeAction()]} />);
    const trigger = screen.getByTestId('actions-menu-trigger');

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    await userEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
  });
});
