import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import UnpublishedIndicator from './UnpublishedIndicator'

describe('UnpublishedIndicator', () => {
  it('renders the dot with the provided testId', () => {
    render(<UnpublishedIndicator testId="section-unpublished-indicator" />)
    expect(screen.getByTestId('section-unpublished-indicator')).toBeInTheDocument()
  })

  // "Contains", not "has": the dot is the container's mark for a change
  // somewhere below it. StatusBadge is what marks a self-change.
  it('exposes the accessible "contains unpublished changes" label as an img role', () => {
    render(<UnpublishedIndicator testId="row-unpublished-indicator" />)
    const dot = screen.getByRole('img', { name: 'Contains unpublished changes' })
    expect(dot).toHaveAttribute('data-testid', 'row-unpublished-indicator')
  })
})
