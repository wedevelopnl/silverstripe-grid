import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ModifiedBadge from './ModifiedBadge'

describe('ModifiedBadge', () => {
  it('renders the pill with the provided testId', () => {
    render(<ModifiedBadge testId="section-modified-badge" />)

    expect(screen.getByTestId('section-modified-badge')).toBeInTheDocument()
  })

  // The badge states the fact in words, so unlike the dot it needs no
  // aria-label — the text content is already the accessible name.
  it('names itself in visible text', () => {
    render(<ModifiedBadge testId="row-modified-badge" />)

    expect(screen.getByTestId('row-modified-badge')).toHaveTextContent('Modified')
  })
})
