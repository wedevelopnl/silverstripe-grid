import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import SectionChrome from './SectionChrome'

const baseProps = {
  status: 'published' as const,
  hasModifiedDescendant: false,
  title: 'Hero Section',
  isCollapsed: false,
  onToggle: vi.fn(),
  children: <span data-testid="body-slot" />,
}

describe('SectionChrome', () => {
  it('renders the frame, header and title', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.getByTestId('section-block')).toBeInTheDocument()
    expect(screen.getByTestId('section-header')).toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section')
  })

  it('applies data-status from the status prop', () => {
    render(<SectionChrome {...baseProps} status="draft" />)

    expect(screen.getByTestId('section-block')).toHaveAttribute('data-status', 'draft')
  })

  it('sets data-collapsed when isCollapsed is true', () => {
    render(<SectionChrome {...baseProps} isCollapsed={true} />)

    expect(screen.getByTestId('section-block')).toHaveAttribute('data-collapsed', '')
  })

  it('does not set data-collapsed when isCollapsed is false', () => {
    render(<SectionChrome {...baseProps} isCollapsed={false} />)

    expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-collapsed')
  })

  it('sets data-drop-target when dropTarget is true', () => {
    render(<SectionChrome {...baseProps} dropTarget={true} />)

    expect(screen.getByTestId('section-block')).toHaveAttribute('data-drop-target', '')
  })

  it('does not set data-drop-target when dropTarget is falsy', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-drop-target')
  })

  it('renders the title as a link when titleHref is provided', () => {
    render(<SectionChrome {...baseProps} titleHref="/admin/pages/edit/show/5" />)

    const link = screen.getByTestId('section-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
    expect(link).toHaveTextContent('Hero Section')
  })

  it('renders the title as plain text when titleHref is omitted', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.queryByTestId('section-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section')
  })

  it('renders the badge, not the dot, when the element itself is modified', () => {
    render(<SectionChrome {...baseProps} status="modified" />)

    expect(screen.getByTestId('section-modified-badge')).toBeInTheDocument()
    expect(screen.queryByTestId('section-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders the dot, not the badge, when only a descendant is modified', () => {
    render(<SectionChrome {...baseProps} status="published" hasModifiedDescendant={true} />)

    expect(screen.getByTestId('section-modified-indicator')).toBeInTheDocument()
    expect(screen.queryByTestId('section-modified-badge')).not.toBeInTheDocument()
  })

  it('renders both marks when the element and a descendant are modified', () => {
    render(<SectionChrome {...baseProps} status="modified" hasModifiedDescendant={true} />)

    expect(screen.getByTestId('section-modified-badge')).toBeInTheDocument()
    expect(screen.getByTestId('section-modified-indicator')).toBeInTheDocument()
  })

  it('sets data-descendant-status only when a descendant is modified', () => {
    const { rerender } = render(<SectionChrome {...baseProps} />)
    expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-descendant-status')

    rerender(<SectionChrome {...baseProps} hasModifiedDescendant={true} />)
    expect(screen.getByTestId('section-block')).toHaveAttribute(
      'data-descendant-status',
      'modified',
    )
  })

  it('renders neither mark when nothing is modified', () => {
    render(<SectionChrome {...baseProps} status="published" />)

    expect(screen.queryByTestId('section-modified-badge')).not.toBeInTheDocument()
    expect(screen.queryByTestId('section-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders the leading and trailing slots when provided', () => {
    render(
      <SectionChrome
        {...baseProps}
        leading={<span data-testid="leading-slot" />}
        trailing={<span data-testid="trailing-slot" />}
      />,
    )

    expect(screen.getByTestId('leading-slot')).toBeInTheDocument()
    expect(screen.getByTestId('trailing-slot')).toBeInTheDocument()
  })

  it('omits the leading and trailing slots when absent', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.queryByTestId('leading-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('trailing-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the section body', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.getByTestId('section-body')).toContainElement(screen.getByTestId('body-slot'))
  })
})
