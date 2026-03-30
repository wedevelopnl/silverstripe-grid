import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import DragHandle from './DragHandle';

describe('DragHandle', () => {
  const defaultProps = {
    listeners: undefined,
    attributes: {
      role: 'button' as const,
      tabIndex: 0,
      'aria-pressed': undefined,
      'aria-disabled': false,
      'aria-roledescription': 'sortable',
      'aria-describedby': 'dnd-desc',
    },
  };

  it('renders with default label "Drag to reorder"', () => {
    render(<DragHandle {...defaultProps} />);

    expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Drag to reorder');
  });

  it('uses custom label when provided', () => {
    render(<DragHandle {...defaultProps} label="Move section" />);

    expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Move section');
  });

  it('spreads attributes onto button', () => {
    render(<DragHandle {...defaultProps} />);

    const button = screen.getByTestId('drag-handle');
    expect(button).toHaveAttribute('aria-roledescription', 'sortable');
    expect(button).toHaveAttribute('aria-describedby', 'dnd-desc');
  });
});
