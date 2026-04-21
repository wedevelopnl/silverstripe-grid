import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSortable } from '@dnd-kit/sortable';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createSimpleElement } from '@/testing/factories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import ElementCard from './ElementCard';

const defaultSortable = {
  attributes: {},
  listeners: {},
  setNodeRef: vi.fn(),
  transform: null,
  transition: undefined,
  isDragging: false,
};

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: vi.fn(() => ({ ...defaultSortable })),
}));

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<
    typeof useSortable
  >);
});

describe('ElementCard', () => {
  it('renders element title', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ title: 'My Content Block' });

    renderWithProviders(<ElementCard element={element} />);

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('My Content Block');
  });

  it('status class applied correctly', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ status: 'draft' });

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

  it('clickable class is exactly "element-card--clickable"', () => {
    mockFetchSuccess({});

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<ElementCard element={element} />);

    const card = screen.getByTestId('element-card');
    expect(card.className).toContain('element-card--clickable');
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
      },
    });

    renderWithProviders(<ElementCard element={element} />);

    const icon = screen.getByTestId('element-card').querySelector('.element-card__icon');
    expect(icon).toHaveClass('element-card__icon', 'font-icon-block-content');
  });

  describe('summary', () => {
    it('renders the summary line when a non-empty value is provided', () => {
      mockFetchSuccess({});

      const element = createSimpleElement({ summary: 'A short preview of the block' });

      renderWithProviders(<ElementCard element={element} />);

      expect(screen.getByTestId('element-card-summary')).toHaveTextContent(
        'A short preview of the block',
      );
    });

    it('does not render the summary line when the field is absent', () => {
      mockFetchSuccess({});

      const element = createSimpleElement();

      renderWithProviders(<ElementCard element={element} />);

      expect(screen.queryByTestId('element-card-summary')).toBeNull();
    });

    it('does not render the summary line when the value is an empty string', () => {
      mockFetchSuccess({});

      const element = createSimpleElement({ summary: '' });

      renderWithProviders(<ElementCard element={element} />);

      expect(screen.queryByTestId('element-card-summary')).toBeNull();
    });
  });

  describe('anchor click handling', () => {
    // Pins the handleAnchorClick guards at ElementCard.tsx:68-82 against
    // ConditionalExpression / LogicalOperator / EqualityOperator / BlockStatement
    // mutations. Each branch (isDragging short-circuit, interactive-descendant
    // detection, plain-text click) is asserted independently.

    it('prevents navigation while a drag is in progress', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isDragging: true,
      } as unknown as ReturnType<typeof useSortable>);
      mockFetchSuccess({});

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });
      renderWithProviders(<ElementCard element={element} />);

      const card = screen.getByTestId('element-card');
      const event = new MouseEvent('click', { bubbles: true, cancelable: true });
      const result = card.dispatchEvent(event);

      // dispatchEvent returns false when preventDefault was called
      expect(result).toBe(false);
      expect(event.defaultPrevented).toBe(true);
    });

    it('prevents navigation when a click originates inside an interactive descendant', () => {
      mockFetchSuccess({});

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' });
      renderWithProviders(<ElementCard element={element} />);

      // The anchor contains a drag-handle <button> (rendered by DragHandle).
      const dragHandle = screen.getByTestId('element-card').querySelector('button');
      expect(dragHandle).not.toBeNull();

      const event = new MouseEvent('click', { bubbles: true, cancelable: true });
      const result = dragHandle!.dispatchEvent(event);

      expect(result).toBe(false);
      expect(event.defaultPrevented).toBe(true);
    });

    it('allows navigation when the click target has no interactive ancestor inside the card', () => {
      mockFetchSuccess({});

      const element = createSimpleElement({
        title: 'Navigate me',
        editLink: '/admin/pages/edit/show/5',
      });
      renderWithProviders(<ElementCard element={element} />);

      // Title is a plain <h4> — no interactive ancestor inside the card.
      const title = screen.getByTestId('element-card-title');
      const event = new MouseEvent('click', { bubbles: true, cancelable: true });
      const result = title.dispatchEvent(event);

      // Not prevented — anchor navigation would proceed.
      expect(result).toBe(true);
      expect(event.defaultPrevented).toBe(false);
    });
  });
});
