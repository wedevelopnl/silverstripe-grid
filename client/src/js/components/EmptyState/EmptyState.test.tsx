import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import EmptyState from './EmptyState'

describe('EmptyState', () => {
  it('renders message text', () => {
    render(<EmptyState message="No elements found" />)

    expect(screen.getByText('No elements found')).toBeInTheDocument()
  })

  it('does not apply the centered variant without variant', () => {
    render(<EmptyState message="No elements found" />)

    const element = screen.getByTestId('empty-state')
    expect(element).not.toHaveAttribute('data-variant', 'centered')
  })

  it('applies the centered variant with variant="centered"', () => {
    render(<EmptyState message="No elements found" variant="centered" />)

    const element = screen.getByTestId('empty-state')
    expect(element).toHaveAttribute('data-variant', 'centered')
  })
})
