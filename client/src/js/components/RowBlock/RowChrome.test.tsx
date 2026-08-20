import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { type ChromeContractOverrides, describeChromeContract } from '@/testing/chromeContract'
import RowChrome from './RowChrome'

const baseProps = {
  status: 'published' as const,
  hasUnpublishedDescendant: false,
  title: 'Main Row',
  isCollapsed: false,
  onToggle: vi.fn(),
  columnCount: 0,
  // Row takes its body id from the caller instead of generating one — the
  // reason it has no collapse-toggle wiring test of its own.
  bodyId: 'row-body-id',
  children: <span data-testid="body-slot" />,
}

describe('RowChrome', () => {
  describeChromeContract({
    prefix: 'row',
    title: 'Main Row',
    renderChrome: (overrides: ChromeContractOverrides = {}) =>
      render(<RowChrome {...baseProps} {...overrides} />),
  })

  it('renders the frame, header and title', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('row-block')).toBeInTheDocument()
    expect(screen.getByTestId('row-header')).toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('renders the column-count meta with the count when columnCount > 0', () => {
    render(<RowChrome {...baseProps} columnCount={3} />)

    expect(screen.getByTestId('row-column-count')).toHaveTextContent('3 columns')
  })

  // Anchored: `toHaveTextContent` matches substrings, so a bare '1 column'
  // would still pass against the '1 columns' this test exists to catch.
  it('says "1 column", not "1 columns", for a single-column row', () => {
    render(<RowChrome {...baseProps} columnCount={1} />)

    expect(screen.getByTestId('row-column-count')).toHaveTextContent(/^1 column$/)
  })

  it('omits the column-count meta when columnCount is 0', () => {
    render(<RowChrome {...baseProps} columnCount={0} />)

    expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
  })

  it('renders children inside the frame', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('body-slot')).toBeInTheDocument()
  })
})
