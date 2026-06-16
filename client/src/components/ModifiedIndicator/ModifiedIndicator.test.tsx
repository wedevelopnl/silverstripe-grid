import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ModifiedIndicator from './ModifiedIndicator'

describe('ModifiedIndicator', () => {
  it('renders the dot with the provided testId', () => {
    render(<ModifiedIndicator testId="section-modified-indicator" />)
    expect(screen.getByTestId('section-modified-indicator')).toBeInTheDocument()
  })

  it('exposes the accessible "has unpublished changes" label as an img role', () => {
    render(<ModifiedIndicator testId="row-modified-indicator" />)
    const dot = screen.getByRole('img', { name: 'Has unpublished changes' })
    expect(dot).toHaveAttribute('data-testid', 'row-modified-indicator')
  })
})
