import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createEnrichedElement } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import ElementCard from './ElementCard';

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: () => ({
    attributes: {},
    listeners: {},
    setNodeRef: vi.fn(),
    transform: null,
    transition: undefined,
    isDragging: false,
  }),
}));

describe('ElementCard', () => {
  it('renders element title', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({ title: 'My Content Block' });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('My Content Block');
  });

  it('renders content preview', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-content',
        type: 'Content',
        title: 'Content',
        summary: 'This is a preview of the content',
      },
    });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByText('This is a preview of the content')).toBeInTheDocument();
  });

  it('shows "No preview available" when summary is empty', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-content',
        type: 'Content',
        title: 'Content',
        summary: '',
      },
    });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByText('No preview available')).toBeInTheDocument();
  });

  it('status class applied correctly', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({
      statusFlags: { addedtodraft: { text: 'Draft', title: 'Draft' } },
    });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).toHaveClass('element-card--draft');
  });

  it('clickable class applied when editLink exists', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).toHaveClass('element-card--clickable');
  });

  it('no clickable class when editLink is null', () => {
    mockFetchSuccess({});

    const element = createEnrichedElement({ editLink: null });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).not.toHaveClass('element-card--clickable');
  });

  it('click navigates to editLink', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    // Intercept location.href assignment via a setter spy
    let capturedHref = '';
    const locationDescriptor = Object.getOwnPropertyDescriptor(window, 'location');
    Object.defineProperty(window, 'location', {
      value: { ...window.location, set href(val: string) { capturedHref = val; }, get href() { return capturedHref || 'http://localhost/'; } },
      writable: true,
      configurable: true,
    });

    const element = createEnrichedElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    await user.click(screen.getByTestId('element-card'));

    expect(capturedHref).toBe('/admin/pages/edit/show/5');

    // Restore original location
    Object.defineProperty(window, 'location', locationDescriptor!);
  });

  it('does not navigate for null editLink', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    let navigated = false;
    const locationDescriptor = Object.getOwnPropertyDescriptor(window, 'location');
    Object.defineProperty(window, 'location', {
      value: { ...window.location, set href(_: string) { navigated = true; }, get href() { return 'http://localhost/'; } },
      writable: true,
      configurable: true,
    });

    const element = createEnrichedElement({ editLink: null });

    renderWithProviders(<ElementCard element={element} />);

    await user.click(screen.getByTestId('element-card'));

    expect(navigated).toBe(false);

    Object.defineProperty(window, 'location', locationDescriptor!);
  });
});
