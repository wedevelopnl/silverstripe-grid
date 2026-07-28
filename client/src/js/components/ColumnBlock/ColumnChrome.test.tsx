import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import ColumnChrome from './ColumnChrome'

const baseProps = {
  status: 'published' as const,
  title: 'Sidebar',
  icon: 'font-icon-block-content',
  isCollapsed: false,
  onToggle: vi.fn(),
  columnStyle: {},
  hidden: false,
  children: <span data-testid="body-slot" />,
}

describe('ColumnChrome', () => {
  it('renders the outer wrapper, card and header', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.getByTestId('column-block-outer')).toBeInTheDocument()
    expect(screen.getByTestId('column-block')).toBeInTheDocument()
    expect(screen.getByTestId('column-header')).toBeInTheDocument()
  })

  it('applies data-status from the status prop', () => {
    render(<ColumnChrome {...baseProps} status="draft" />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-status', 'draft')
  })

  it('sets data-collapsed when isCollapsed is true', () => {
    render(<ColumnChrome {...baseProps} isCollapsed={true} />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-collapsed', '')
  })

  it('does not set data-collapsed when isCollapsed is false', () => {
    render(<ColumnChrome {...baseProps} isCollapsed={false} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-collapsed')
  })

  it('sets data-hidden when hidden is true', () => {
    render(<ColumnChrome {...baseProps} hidden={true} />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-hidden', '')
  })

  it('does not set data-hidden when hidden is false', () => {
    render(<ColumnChrome {...baseProps} hidden={false} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-hidden')
  })

  it('sets data-drop-target when dropTarget is true', () => {
    render(<ColumnChrome {...baseProps} dropTarget={true} />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-drop-target', '')
  })

  it('does not set data-drop-target when dropTarget is falsy', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-drop-target')
  })

  it('renders the title as a link when titleHref is provided', () => {
    render(<ColumnChrome {...baseProps} titleHref="/admin/pages/edit/show/7" />)

    const link = screen.getByTestId('column-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/7')
    expect(link).toHaveTextContent('Sidebar')
  })

  it('renders the title as plain text when titleHref is omitted', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.queryByTestId('column-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('column-title')).toHaveTextContent('Sidebar')
  })

  it('renders the modified indicator when status is modified', () => {
    render(<ColumnChrome {...baseProps} status="modified" />)

    expect(screen.getByTestId('column-modified-indicator')).toBeInTheDocument()
  })

  it('does not render the modified indicator when status is published', () => {
    render(<ColumnChrome {...baseProps} status="published" />)

    expect(screen.queryByTestId('column-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders each optional slot when provided', () => {
    render(
      <ColumnChrome
        {...baseProps}
        insertBefore={<span data-testid="insert-slot" />}
        leading={<span data-testid="leading-slot" />}
        trailing={<span data-testid="trailing-slot" />}
        layoutSettings={<span data-testid="layout-slot" />}
        footer={<span data-testid="footer-slot" />}
        overlay={<span data-testid="overlay-slot" />}
      />,
    )

    expect(screen.getByTestId('insert-slot')).toBeInTheDocument()
    expect(screen.getByTestId('leading-slot')).toBeInTheDocument()
    expect(screen.getByTestId('trailing-slot')).toBeInTheDocument()
    expect(screen.getByTestId('layout-slot')).toBeInTheDocument()
    // The layout-settings wrapper div is only rendered when layoutSettings is provided.
    expect(document.querySelector('.ssgrid-column__layout-settings')).not.toBeNull()
    expect(screen.getByTestId('footer-slot')).toBeInTheDocument()
    expect(screen.getByTestId('overlay-slot')).toBeInTheDocument()
  })

  it('omits the optional slots when absent', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.queryByTestId('insert-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('leading-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('trailing-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('layout-slot')).not.toBeInTheDocument()
    // The `layoutSettings !== undefined` guard must omit the wrapper div entirely
    // (not render an empty one) when the slot is absent.
    expect(document.querySelector('.ssgrid-column__layout-settings')).toBeNull()
    expect(screen.queryByTestId('footer-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('overlay-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the column body', () => {
    render(<ColumnChrome {...baseProps} />)

    expect(screen.getByTestId('column-body')).toContainElement(screen.getByTestId('body-slot'))
  })
})
