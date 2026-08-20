import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { type ChromeContractOverrides, describeChromeContract } from '@/testing/chromeContract'
import ColumnChrome from './ColumnChrome'

const baseProps = {
  status: 'published' as const,
  hasUnpublishedDescendant: false,
  title: 'Sidebar',
  icon: 'font-icon-block-content',
  isCollapsed: false,
  onToggle: vi.fn(),
  columnStyle: {},
  hidden: false,
  children: <span data-testid="body-slot" />,
}

describe('ColumnChrome', () => {
  describeChromeContract({
    prefix: 'column',
    title: 'Sidebar',
    renderChrome: (overrides: ChromeContractOverrides = {}) =>
      render(<ColumnChrome {...baseProps} {...overrides} />),
  })

  it('renders the outer wrapper, card and header', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.getByTestId('column-block-outer')).toBeInTheDocument()
    expect(screen.getByTestId('column-block')).toBeInTheDocument()
    expect(screen.getByTestId('column-header')).toBeInTheDocument()
  })

  it('sets data-hidden when hidden is true', () => {
    render(<ColumnChrome {...baseProps} hidden={true} />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-hidden', '')
  })

  it('does not set data-hidden when hidden is false', () => {
    render(<ColumnChrome {...baseProps} hidden={false} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-hidden')
  })

  it('renders each column-specific optional slot when provided', () => {
    render(
      <ColumnChrome
        {...baseProps}
        insertBefore={<span data-testid="insert-slot" />}
        layoutSettings={<span data-testid="layout-slot" />}
        footer={<span data-testid="footer-slot" />}
        overlay={<span data-testid="overlay-slot" />}
      />,
    )

    expect(screen.getByTestId('insert-slot')).toBeInTheDocument()
    expect(screen.getByTestId('layout-slot')).toBeInTheDocument()
    // The layout-settings wrapper div is only rendered when layoutSettings is provided.
    expect(document.querySelector('.ssgrid-column-layout-settings')).not.toBeNull()
    expect(screen.getByTestId('footer-slot')).toBeInTheDocument()
    expect(screen.getByTestId('overlay-slot')).toBeInTheDocument()
  })

  it('omits the column-specific optional slots when absent', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.queryByTestId('insert-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('layout-slot')).not.toBeInTheDocument()
    // The `layoutSettings !== undefined` guard must omit the wrapper div entirely
    // (not render an empty one) when the slot is absent.
    expect(document.querySelector('.ssgrid-column-layout-settings')).toBeNull()
    expect(screen.queryByTestId('footer-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('overlay-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the column body', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.getByTestId('column-body')).toContainElement(screen.getByTestId('body-slot'))
  })

  it('wires the collapse toggle to the body it controls', () => {
    render(<ColumnChrome {...baseProps} />)

    const controlsId = screen.getByTestId('collapse-toggle').getAttribute('aria-controls')

    expect(controlsId).not.toBeNull()
    expect(screen.getByTestId('column-body')).toHaveAttribute('id', controlsId)
  })
})
