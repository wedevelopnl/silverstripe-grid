import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import RowChrome from './RowChrome'

const baseProps = {
  status: 'published' as const,
  title: 'Main Row',
  isCollapsed: false,
  onToggle: vi.fn(),
  columnCount: 0,
  children: <span data-testid="body-slot" />,
}

describe('RowChrome', () => {
  it('renders the frame, header and title', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('row-block')).toBeInTheDocument()
    expect(screen.getByTestId('row-header')).toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('applies data-status from the status prop', () => {
    render(<RowChrome {...baseProps} status="draft" />)

    expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'draft')
  })

  it('sets data-collapsed when isCollapsed is true', () => {
    render(<RowChrome {...baseProps} isCollapsed={true} />)

    expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
  })

  it('does not set data-collapsed when isCollapsed is false', () => {
    render(<RowChrome {...baseProps} isCollapsed={false} />)

    expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-collapsed')
  })

  it('sets data-drop-target when dropTarget is true', () => {
    render(<RowChrome {...baseProps} dropTarget={true} />)

    expect(screen.getByTestId('row-block')).toHaveAttribute('data-drop-target', '')
  })

  it('does not set data-drop-target when dropTarget is falsy', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-drop-target')
  })

  it('renders the title as a link when titleHref is provided', () => {
    render(<RowChrome {...baseProps} titleHref="/admin/pages/edit/show/10" />)

    const link = screen.getByTestId('row-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/10')
    expect(link).toHaveTextContent('Main Row')
  })

  it('renders the title as plain text when titleHref is omitted', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('renders the modified indicator when status is modified', () => {
    render(<RowChrome {...baseProps} status="modified" />)

    expect(screen.getByTestId('row-modified-indicator')).toBeInTheDocument()
  })

  it('does not render the modified indicator when status is published', () => {
    render(<RowChrome {...baseProps} status="published" />)

    expect(screen.queryByTestId('row-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders the column-count meta with the count when columnCount > 0', () => {
    render(<RowChrome {...baseProps} columnCount={3} />)

    expect(screen.getByTestId('row-column-count')).toHaveTextContent('3 columns')
  })

  it('omits the column-count meta when columnCount is 0', () => {
    render(<RowChrome {...baseProps} columnCount={0} />)

    expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
  })

  it('renders the dragHandle and actions slots when provided', () => {
    render(
      <RowChrome
        {...baseProps}
        dragHandle={<span data-testid="handle-slot" />}
        actions={<span data-testid="actions-slot" />}
      />,
    )

    expect(screen.getByTestId('handle-slot')).toBeInTheDocument()
    expect(screen.getByTestId('actions-slot')).toBeInTheDocument()
  })

  it('omits the dragHandle and actions slots when absent', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.queryByTestId('handle-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('actions-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the frame', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('body-slot')).toBeInTheDocument()
  })
})
