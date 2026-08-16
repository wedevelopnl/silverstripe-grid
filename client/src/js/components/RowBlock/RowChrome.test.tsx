import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import RowChrome from './RowChrome'

const baseProps = {
  status: 'published' as const,
  hasUnpublishedDescendant: false,
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

  it('badges the element and adds no descendant note when only the element is modified', () => {
    render(<RowChrome {...baseProps} status="modified" />)

    expect(screen.getByTestId('row-status-badge')).toBeInTheDocument()
    expect(screen.queryByTestId('row-unpublished-indicator')).not.toBeInTheDocument()
  })

  it('announces the descendant, with no badge, when only a descendant is modified', () => {
    render(<RowChrome {...baseProps} status="published" hasUnpublishedDescendant={true} />)

    expect(screen.getByTestId('row-unpublished-indicator')).toBeInTheDocument()
    expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
  })

  it('badges the element and announces the descendant when both are modified', () => {
    render(<RowChrome {...baseProps} status="modified" hasUnpublishedDescendant={true} />)

    expect(screen.getByTestId('row-status-badge')).toBeInTheDocument()
    expect(screen.getByTestId('row-unpublished-indicator')).toBeInTheDocument()
  })

  it('sets data-descendant-unpublished only when a descendant is unpublished', () => {
    const { rerender } = render(<RowChrome {...baseProps} />)
    expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-descendant-unpublished')

    rerender(<RowChrome {...baseProps} hasUnpublishedDescendant={true} />)
    expect(screen.getByTestId('row-block')).toHaveAttribute('data-descendant-unpublished')
  })

  it('badges a never-published element as draft', () => {
    render(<RowChrome {...baseProps} status="draft" />)

    expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Draft')
  })

  it('renders neither mark when nothing is unpublished', () => {
    render(<RowChrome {...baseProps} status="published" />)

    expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
    expect(screen.queryByTestId('row-unpublished-indicator')).not.toBeInTheDocument()
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

  it('renders the leading and trailing slots when provided', () => {
    render(
      <RowChrome
        {...baseProps}
        leading={<span data-testid="leading-slot" />}
        trailing={<span data-testid="trailing-slot" />}
      />,
    )

    expect(screen.getByTestId('leading-slot')).toBeInTheDocument()
    expect(screen.getByTestId('trailing-slot')).toBeInTheDocument()
  })

  it('omits the leading and trailing slots when absent', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.queryByTestId('leading-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('trailing-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the frame', () => {
    render(<RowChrome {...baseProps} />)

    expect(screen.getByTestId('body-slot')).toBeInTheDocument()
  })
})
