import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import StatusBadge from './StatusBadge'

describe('StatusBadge', () => {
  // The label is the only thing separating the two — they share a pill so that
  // "not live yet" reads as one idea, matching the CMS page tree's own hue.
  it('names a never-published element "Draft"', () => {
    render(<StatusBadge status="draft" testId="section-status-badge" />)

    expect(screen.getByTestId('section-status-badge')).toHaveTextContent('Draft')
  })

  it('names a changed-since-publish element "Modified"', () => {
    render(<StatusBadge status="modified" testId="row-status-badge" />)

    expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Modified')
  })

  it('exposes the status so the two can be told apart in the DOM', () => {
    render(<StatusBadge status="draft" testId="row-status-badge" />)

    expect(screen.getByTestId('row-status-badge')).toHaveAttribute('data-status', 'draft')
  })

  // Callers render this unconditionally, so it has to hold its own.
  it('renders nothing for a published element', () => {
    render(<StatusBadge status="published" testId="row-status-badge" />)

    expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
  })

  it('renders nothing for a removed element', () => {
    render(<StatusBadge status="removed" testId="row-status-badge" />)

    expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
  })
})
