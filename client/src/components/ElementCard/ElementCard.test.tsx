import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createSimpleElement } from '@/testing/factories';
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

    const element = createSimpleElement({ title: 'My Content Block' });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('My Content Block');
  });

  it('renders content preview', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
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

  it('shows exact "No preview available" text when summary is empty and has empty class', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
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

    const content = screen.getByText('No preview available');
    expect(content.textContent).toBe('No preview available');
    expect(content).toHaveClass('element-card__content', 'element-card__content--empty');
  });

  it('status class applied correctly', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
      statusFlags: { addedtodraft: { text: 'Draft', title: 'Draft' } },
    });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).toHaveClass('element-card--draft');
  });

  it('clickable class applied when editLink exists', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).toHaveClass('element-card--clickable');
  });

  it('no clickable class when editLink is null', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: null });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).not.toHaveClass('element-card--clickable');
  });

  it('renders an anchor with an href so middle-click opens in a new tab', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    const link = screen.getByRole('link');
    expect(link.tagName).toBe('A');
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5');
  });

  it('renders as non-interactive when editLink is null', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: null });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.queryByRole('link')).toBeNull();
  });

  it('applies element-card base class always', () => {
    mockFetchSuccess({});

    const element = createSimpleElement();

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card')).toHaveClass('element-card');
  });

  it('empty content area has the --empty modifier class', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
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

    const contentDiv = screen.getByText('No preview available');
    expect(contentDiv).toHaveClass('element-card__content', 'element-card__content--empty');
  });

  it('non-empty content area does not have the --empty modifier class', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-content',
        type: 'Content',
        title: 'Content',
        summary: 'Some text',
      },
    });

    renderWithProviders(<ElementCard element={element} />);

    const contentDiv = screen.getByText('Some text');
    expect(contentDiv).toHaveClass('element-card__content');
    expect(contentDiv).not.toHaveClass('element-card__content--empty');
  });

  it('clickable class is exactly "element-card--clickable"', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    const card = screen.getByTestId('element-card');
    expect(card.className).toContain('element-card--clickable');
  });

  it('content class is exactly "element-card__content--empty" for empty summary', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
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

    const content = screen.getByText('No preview available');
    expect(content.className).toBe('element-card__content element-card__content--empty');
  });

  it('renders the icon with the blockSchema icon class', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: 'Content',
        summary: '',
      },
    });

    renderWithProviders(<ElementCard element={element} />);

    const icon = screen.getByTestId('element-card').querySelector('.element-card__icon');
    expect(icon).toHaveClass('element-card__icon', 'font-icon-block-content');
  });
});
