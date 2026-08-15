import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ModifiedIndicator from './ModifiedIndicator'

describe('ModifiedIndicator', () => {
  it('renders the dot with the provided testId', () => {
    render(<ModifiedIndicator testId="section-modified-indicator" />)
    expect(screen.getByTestId('section-modified-indicator')).toBeInTheDocument()
  })

  // "Contains", not "has": the dot is the container's mark for a change
  // somewhere below it. ModifiedBadge is what marks a self-change.
  it('exposes the accessible "contains unpublished changes" label as an img role', () => {
    render(<ModifiedIndicator testId="row-modified-indicator" />)
    const dot = screen.getByRole('img', { name: 'Contains unpublished changes' })
    expect(dot).toHaveAttribute('data-testid', 'row-modified-indicator')
  })
})
