import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import EmptyState from './EmptyState';

describe('EmptyState', () => {
  it('renders message text', () => {
    render(<EmptyState message="No elements found" />);

    expect(screen.getByText('No elements found')).toBeInTheDocument();
  });

  it('applies default class without variant', () => {
    render(<EmptyState message="No elements found" />);

    const element = screen.getByText('No elements found');
    expect(element).toHaveClass('empty-state');
    expect(element).not.toHaveClass('empty-state--centered');
  });

  it('applies centered class with variant="centered"', () => {
    render(<EmptyState message="No elements found" variant="centered" />);

    const element = screen.getByText('No elements found');
    expect(element).toHaveClass('empty-state', 'empty-state--centered');
  });
});
