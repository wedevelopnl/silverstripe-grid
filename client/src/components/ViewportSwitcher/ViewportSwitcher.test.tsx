import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient } from '@tanstack/react-query';
import { describe, expect, it } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createTreeApiResponse } from '@/testing/factories';
import { renderWithProviders } from '@/testing/renderWithProviders';
import { queryKeys } from '@/hooks/queryKeys';

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

  it('active button has aria-disabled attribute', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    const mdButton = buttons[2];

    expect(mdButton).toHaveAttribute('aria-disabled', 'true');
  });

  it('inactive buttons do not have aria-disabled attribute', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    // xs (index 0) is inactive
    expect(buttons[0]).not.toHaveAttribute('aria-disabled');
    // lg (index 3) is inactive
    expect(buttons[3]).not.toHaveAttribute('aria-disabled');
  });

  it('inactive buttons do not have active class', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');

    expect(buttons[0]).not.toHaveClass('viewport-switcher__button--active');
    expect(buttons[0]).toHaveAttribute('aria-pressed', 'false');
  });

  it('clicking active button does not change active state', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    const mdButton = buttons[2];

    // Click active button
    await user.click(mdButton);

    // md should still be active
    expect(mdButton).toHaveClass('viewport-switcher__button--active');
    expect(mdButton).toHaveAttribute('aria-pressed', 'true');

    // Other buttons should still be inactive
    expect(buttons[0]).not.toHaveClass('viewport-switcher__button--active');
    expect(buttons[3]).not.toHaveClass('viewport-switcher__button--active');
  });

  it('inactive buttons do not have aria-disabled attribute at all', () => {
    mockFetchSuccess({});

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' });

    const buttons = screen.getAllByTestId('viewport-button');
    // All non-active buttons should lack aria-disabled entirely
    for (const [index, button] of buttons.entries()) {
      if (index === 2) continue; // skip md (active)
      expect(button).not.toHaveAttribute('aria-disabled');
    }
  });

  it('shows reset button when overrides exist in cache', () => {
    mockFetchSuccess({});

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });

    queryClient.setQueryData(
      queryKeys.elementTree.byPage(1, 'main'),
      createTreeApiResponse({ overrideCounts: { _total: 5, md: 3 } }),
    );

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient });

    const resetButton = screen.getByTestId('reset-overrides-button');
    expect(resetButton).toBeInTheDocument();
    expect(resetButton).toHaveTextContent('Reset all');
  });

  it('shows "Reset viewport" label for non-default viewport with overrides', () => {
    mockFetchSuccess({});

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    });

    queryClient.setQueryData(
      queryKeys.elementTree.byPage(1, 'main'),
      createTreeApiResponse({ overrideCounts: { _total: 5, lg: 2 } }),
    );

    renderWithProviders(<ViewportSwitcher />, { viewport: 'lg', queryClient });

    const resetButton = screen.getByTestId('reset-overrides-button');
    expect(resetButton).toBeInTheDocument();
    expect(resetButton).toHaveTextContent('Reset viewport');
  });
});
