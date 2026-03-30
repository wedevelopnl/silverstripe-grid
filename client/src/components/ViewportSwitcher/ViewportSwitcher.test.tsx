import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { renderWithProviders } from '@/testing/renderWithProviders';

import ViewportSwitcher from './ViewportSwitcher';

describe('ViewportSwitcher', () => {
  it('renders buttons for all viewports', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />);

    const buttons = screen.getAllByTestId('viewport-button');

    // Setup file configures 6 viewports: xs, sm, md, lg, xl, xxl
    expect(buttons).toHaveLength(6);
    expect(buttons[0]).toHaveTextContent('Extra small');
    expect(buttons[5]).toHaveTextContent('Extra extra large');
  });

  it('active viewport button has active class and aria-pressed', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    const mdButton = buttons[2]; // md is the third viewport

    expect(mdButton).toHaveClass('viewport-switcher__button--active');
    expect(mdButton).toHaveAttribute('aria-pressed', 'true');
  });

  it('clicking inactive button switches viewport', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    const lgButton = buttons[3]; // lg is the fourth viewport

    expect(lgButton).not.toHaveClass('viewport-switcher__button--active');

    await user.click(lgButton);

    // After clicking, lg should become active
    expect(lgButton).toHaveClass('viewport-switcher__button--active');
    expect(lgButton).toHaveAttribute('aria-pressed', 'true');

    // md should no longer be active
    expect(buttons[2]).not.toHaveClass('viewport-switcher__button--active');
  });

  it('active button click does not call setActiveViewport', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    const mdButton = buttons[2];

    await user.click(mdButton);

    // md should still be active, no change
    expect(mdButton).toHaveClass('viewport-switcher__button--active');
    expect(mdButton).toHaveAttribute('aria-pressed', 'true');
  });

  it('reset button not shown when no overrides', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />);

    expect(screen.queryByTestId('reset-overrides-button')).not.toBeInTheDocument();
  });
});
