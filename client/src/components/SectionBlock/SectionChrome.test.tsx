import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import SectionChrome from './SectionChrome'

const baseProps = {
  status: 'published' as const,
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

  it('renders the modified indicator when status is modified', () => {
    render(<SectionChrome {...baseProps} status="modified" />)

    expect(screen.getByTestId('section-modified-indicator')).toBeInTheDocument()
  })

  it('does not render the modified indicator when status is published', () => {
    render(<SectionChrome {...baseProps} status="published" />)

    expect(screen.queryByTestId('section-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders the dragHandle and actions slots when provided', () => {
    render(
      <SectionChrome
        {...baseProps}
        dragHandle={<span data-testid="handle-slot" />}
        actions={<span data-testid="actions-slot" />}
      />,
    )

    expect(screen.getByTestId('handle-slot')).toBeInTheDocument()
    expect(screen.getByTestId('actions-slot')).toBeInTheDocument()
  })

  it('omits the dragHandle and actions slots when absent', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.queryByTestId('handle-slot')).not.toBeInTheDocument()
    expect(screen.queryByTestId('actions-slot')).not.toBeInTheDocument()
  })

  it('renders children inside the section body', () => {
    const { container } = render(<SectionChrome {...baseProps} />)

    const body = container.querySelector('.ssgrid-section__body')
    expect(body).not.toBeNull()
    expect(body).toContainElement(screen.getByTestId('body-slot'))
  })
})
