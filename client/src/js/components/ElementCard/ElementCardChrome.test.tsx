import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ElementCardChrome from './ElementCardChrome'

describe('ElementCardChrome', () => {
  it('renders an anchor with clickable state when href is provided', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        href="/admin/pages/edit/show/5"
      />,
    )

    const link = screen.getByRole('link')
    expect(link.tagName).toBe('A')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
    expect(link).toHaveAttribute('data-state', 'clickable')
  })

  it('renders a div with no link when href is omitted', () => {
    render(<ElementCardChrome status="published" icon="font-icon-block-content" title="Block" />)

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByTestId('element-card').tagName).toBe('DIV')
  })

  // A block is a leaf, so it only ever carries the self mark — the "contains a
  // change" dot belongs to containers.
  it('renders the modified badge when status is modified', () => {
    render(<ElementCardChrome status="modified" icon="font-icon-block-content" title="Block" />)

    expect(screen.getByTestId('element-card-status-badge')).toBeInTheDocument()
  })

  it('does not render the modified badge when status is published', () => {
    render(<ElementCardChrome status="published" icon="font-icon-block-content" title="Block" />)

    expect(screen.queryByTestId('element-card-status-badge')).toBeNull()
  })

  it('renders the leading and trailing slots when provided', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        leading={<span data-testid="leading-slot" />}
        trailing={<span data-testid="trailing-slot" />}
      />,
    )

    expect(screen.getByTestId('leading-slot')).toBeInTheDocument()
    expect(screen.getByTestId('trailing-slot')).toBeInTheDocument()
  })

  it('omits the leading and trailing slots when absent', () => {
    render(<ElementCardChrome status="published" icon="font-icon-block-content" title="Block" />)

    expect(screen.queryByTestId('leading-slot')).toBeNull()
    expect(screen.queryByTestId('trailing-slot')).toBeNull()
  })

  it('renders the summary paragraph when summary is truthy', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        summary="A short preview"
      />,
    )

    expect(screen.getByTestId('element-card-summary')).toHaveTextContent('A short preview')
  })

  it('omits the summary paragraph when summary is absent', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        summary={undefined}
      />,
    )

    expect(screen.queryByTestId('element-card-summary')).toBeNull()
  })

  it('omits the summary paragraph when summary is an empty string', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        summary=""
      />,
    )

    expect(screen.queryByTestId('element-card-summary')).toBeNull()
  })
})
