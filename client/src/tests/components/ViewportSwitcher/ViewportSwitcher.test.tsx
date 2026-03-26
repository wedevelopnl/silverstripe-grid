import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ViewportSwitcher from '@/components/ViewportSwitcher/ViewportSwitcher';
import { createViewportWrapper } from '@/tests/helpers/viewportTestUtils';

vi.mock('@/utils/gridAdapter', () => ({
  getViewports: vi.fn(() => [
    { key: 'xs', label: 'Extra Small' },
    { key: 'sm', label: 'Small' },
    { key: 'md', label: 'Medium' },
    { key: 'lg', label: 'Large' },
  ]),
  getDefaultViewport: vi.fn(() => 'md'),
}));

vi.mock('@/hooks/useResetOverridesAction', () => ({
  useResetOverridesAction: vi.fn(() => ({
    showReset: false,
    label: '',
    affectedCount: 0,
    isDialogOpen: false,
    dialogTitle: '',
    dialogMessage: '',
    onResetClick: vi.fn(),
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
  })),
}));

describe('ViewportSwitcher', () => {
  it('renders a button per viewport', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('xs') });

    const buttons = screen.getAllByRole('button');
    expect(buttons).toHaveLength(4);
  });

  it('renders viewport labels as button text', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('xs') });

    expect(screen.getByText('Extra Small')).toBeDefined();
    expect(screen.getByText('Small')).toBeDefined();
    expect(screen.getByText('Medium')).toBeDefined();
    expect(screen.getByText('Large')).toBeDefined();
  });

  it('marks active viewport button as aria-pressed="true"', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const activeButton = screen.getByText('Small');
    expect(activeButton.getAttribute('aria-pressed')).toBe('true');
  });

  it('marks inactive viewport buttons as aria-pressed="false"', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const inactiveLabels = ['Extra Small', 'Medium', 'Large'];
    for (const label of inactiveLabels) {
      const button = screen.getByText(label);
      expect(button.getAttribute('aria-pressed')).toBe('false');
    }
  });

  it('switches active viewport when a button is clicked', async () => {
    const user = userEvent.setup();

    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('xs') });

    await user.click(screen.getByText('Medium'));

    expect(screen.getByText('Medium').getAttribute('aria-pressed')).toBe('true');
    expect(screen.getByText('Extra Small').getAttribute('aria-pressed')).toBe('false');
  });

  it('applies active modifier class to the active viewport button', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const activeButton = screen.getByText('Small');
    expect(activeButton.classList.contains('viewport-switcher__button--active')).toBe(true);
  });

  it('does not apply active modifier class to inactive viewport buttons', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const inactiveLabels = ['Extra Small', 'Medium', 'Large'];
    for (const label of inactiveLabels) {
      const button = screen.getByText(label);
      expect(button.classList.contains('viewport-switcher__button--active')).toBe(false);
      expect(button.classList.contains('viewport-switcher__button')).toBe(true);
    }
  });

  it('marks active viewport button as aria-disabled', () => {
    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const activeButton = screen.getByText('Small');
    expect(activeButton.getAttribute('aria-disabled')).toBe('true');

    const inactiveLabels = ['Extra Small', 'Medium', 'Large'];
    for (const label of inactiveLabels) {
      const button = screen.getByText(label);
      expect(button.hasAttribute('aria-disabled')).toBe(false);
    }
  });

  it('does not change state when clicking the already-active button', async () => {
    const user = userEvent.setup();

    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    await user.click(screen.getByText('Small'));

    // Still active after clicking
    expect(screen.getByText('Small').getAttribute('aria-pressed')).toBe('true');
  });

  it('does not call setActiveViewport when clicking the already-active button', async () => {
    const user = userEvent.setup();

    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    // Click an inactive button first to verify switching works
    await user.click(screen.getByText('Medium'));
    expect(screen.getByText('Medium').getAttribute('aria-pressed')).toBe('true');

    // Now click the now-active Medium button again — should be a no-op
    await user.click(screen.getByText('Medium'));

    // Medium should still be active, not toggled off
    expect(screen.getByText('Medium').getAttribute('aria-pressed')).toBe('true');
    // And no other button should have become active
    expect(screen.getByText('Small').getAttribute('aria-pressed')).toBe('false');
  });

  it('shows reset button when overrides exist', async () => {
    const { useResetOverridesAction } = vi.mocked(
      await import('@/hooks/useResetOverridesAction'),
    );
    useResetOverridesAction.mockReturnValue({
      showReset: true,
      label: 'Reset viewport',
      affectedCount: 3,
      isDialogOpen: false,
      dialogTitle: 'Reset Small overrides',
      dialogMessage: 'Reset 3 columns override for Small?',
      onResetClick: vi.fn(),
      onConfirm: vi.fn(),
      onCancel: vi.fn(),
    });

    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    const resetButton = screen.getByTestId('reset-overrides-button');
    expect(resetButton).toBeDefined();
    expect(resetButton.textContent).toBe('Reset viewport');
  });

  it('hides reset button when no overrides exist', async () => {
    const { useResetOverridesAction } = vi.mocked(
      await import('@/hooks/useResetOverridesAction'),
    );
    useResetOverridesAction.mockReturnValue({
      showReset: false,
      label: '',
      affectedCount: 0,
      isDialogOpen: false,
      dialogTitle: '',
      dialogMessage: '',
      onResetClick: vi.fn(),
      onConfirm: vi.fn(),
      onCancel: vi.fn(),
    });

    render(<ViewportSwitcher />, { wrapper: createViewportWrapper('sm') });

    expect(screen.queryByTestId('reset-overrides-button')).toBeNull();
  });
});
