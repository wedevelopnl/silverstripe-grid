import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import ActionsMenu, { type ActionItem } from './ActionsMenu';

function createActions(overrides: Partial<ActionItem>[] = []): ActionItem[] {
  const defaults: ActionItem[] = [
    { key: 'edit', label: 'Edit', onAction: vi.fn() },
    { key: 'delete', label: 'Delete', destructive: true, onAction: vi.fn() },
  ];

  return defaults.map((action, index) => ({
    ...action,
    ...overrides[index],
  }));
}

describe('ActionsMenu', () => {
  it('returns null when actions array is empty', () => {
    const { container } = render(<ActionsMenu actions={[]} />);

    expect(container.innerHTML).toBe('');
  });

  it('opens menu on trigger click', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    expect(screen.getByRole('menu')).toBeInTheDocument();
  });

  it('closes menu on second trigger click', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    const trigger = screen.getByTestId('actions-menu-trigger');

    await user.click(trigger);
    expect(screen.getByRole('menu')).toBeInTheDocument();

    await user.click(trigger);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('closes on outside click', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));
    expect(screen.getByRole('menu')).toBeInTheDocument();

    await user.click(document.body);

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('closes on Escape key', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));
    expect(screen.getByRole('menu')).toBeInTheDocument();

    await user.keyboard('{Escape}');

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('fires action callback on menu item click', async () => {
    const user = userEvent.setup();
    const onAction = vi.fn();
    const actions = createActions([{ onAction }]);

    render(<ActionsMenu actions={actions} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));
    await user.click(screen.getByText('Edit'));

    expect(onAction).toHaveBeenCalledOnce();
  });

  it('fires action callback on Enter key on menu item', async () => {
    const user = userEvent.setup();
    const onAction = vi.fn();
    const actions = createActions([{ onAction }]);

    render(<ActionsMenu actions={actions} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    screen.getByText('Edit').focus();
    await user.keyboard('{Enter}');

    expect(onAction).toHaveBeenCalledOnce();
  });

  it('fires action callback on Space key on menu item', async () => {
    const user = userEvent.setup();
    const onAction = vi.fn();
    const actions = createActions([{ onAction }]);

    render(<ActionsMenu actions={actions} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    screen.getByText('Edit').focus();
    await user.keyboard(' ');

    expect(onAction).toHaveBeenCalledOnce();
  });

  it('closes menu after action fires', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));
    await user.click(screen.getByText('Edit'));

    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('applies destructive CSS class to destructive actions', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const deleteItem = screen.getByText('Delete');

    expect(deleteItem).toHaveClass('actions-menu__item--destructive');
    expect(screen.getByText('Edit')).not.toHaveClass('actions-menu__item--destructive');
  });

  it('toggles aria-expanded with menu state', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    const trigger = screen.getByTestId('actions-menu-trigger');

    expect(trigger).toHaveAttribute('aria-expanded', 'false');

    await user.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'true');

    await user.click(trigger);
    expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  it('trigger has aria-controls pointing to menu id when open', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    const trigger = screen.getByTestId('actions-menu-trigger');

    // Closed: no aria-controls
    expect(trigger).not.toHaveAttribute('aria-controls');

    await user.click(trigger);

    // Open: aria-controls references the dropdown id
    expect(trigger).toHaveAttribute('aria-controls', 'actions-menu-menu');
  });

  it('trigger has aria-haspopup="menu"', () => {
    render(<ActionsMenu actions={createActions()} />);

    expect(screen.getByTestId('actions-menu-trigger')).toHaveAttribute('aria-haspopup', 'menu');
  });

  it('trigger has aria-label "Actions"', () => {
    render(<ActionsMenu actions={createActions()} />);

    expect(screen.getByTestId('actions-menu-trigger')).toHaveAttribute('aria-label', 'Actions');
  });

  it('dropdown has role="menu"', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const dropdown = screen.getByTestId('actions-menu-dropdown');
    expect(dropdown).toHaveAttribute('role', 'menu');
  });

  it('menu items have role="menuitem"', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const items = screen.getAllByRole('menuitem');
    expect(items).toHaveLength(2);
  });

  it('trigger has actions-menu__trigger class', () => {
    render(<ActionsMenu actions={createActions()} />);

    expect(screen.getByTestId('actions-menu-trigger')).toHaveClass('actions-menu__trigger');
  });

  it('non-destructive item does not have actions-menu__item--destructive class', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    expect(screen.getByText('Edit')).toHaveClass('actions-menu__item');
    expect(screen.getByText('Edit')).not.toHaveClass('actions-menu__item--destructive');
  });

  it('uses custom testId for dropdown', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} testId="custom-menu" />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    expect(screen.getByTestId('custom-menu-dropdown')).toBeInTheDocument();
  });

  it('outside click while menu is closed does not open the menu', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    // Menu starts closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();

    // Click document body while closed
    await user.click(document.body);

    // Menu should still be closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('Escape key while menu is closed does not cause errors', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    // Menu starts closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();

    // Press Escape while closed
    await user.keyboard('{Escape}');

    // Menu should still be closed, no errors
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
  });

  it('destructive item has exact CSS class string', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const deleteItem = screen.getByText('Delete');
    expect(deleteItem.className).toBe('actions-menu__item actions-menu__item--destructive');
  });

  it('non-destructive item has only the base CSS class', async () => {
    const user = userEvent.setup();

    render(<ActionsMenu actions={createActions()} />);

    await user.click(screen.getByTestId('actions-menu-trigger'));

    const editItem = screen.getByText('Edit');
    expect(editItem.className).toBe('actions-menu__item');
  });

});
