import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import ElementCardChrome from './ElementCardChrome'

describe('ElementCardChrome', () => {
  it('renders a stretched title link inside a clickable card when href is provided', () => {
    render(
      <ElementCardChrome
        status="published"
        icon="font-icon-block-content"
        title="Block"
        href="/admin/pages/edit/show/5"
      />,
    )

    // The card itself is a <div> with the clickable state; the anchor wraps only
    // the title (its ::after stretches over the card via CSS), so interactive
    // controls can be siblings rather than nested inside the anchor.
    const card = screen.getByTestId('element-card')
    expect(card.tagName).toBe('DIV')
    expect(card).toHaveAttribute('data-state', 'clickable')

    const link = screen.getByRole('link')
    expect(link.tagName).toBe('A')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
    expect(link).toHaveAttribute('data-testid', 'element-card-link')
    // The title lives inside the anchor.
    expect(link).toContainElement(screen.getByTestId('element-card-title'))
  })

  it('renders a div with no link when href is omitted', () => {
    render(<ElementCardChrome status="published" icon="font-icon-block-content" title="Block" />)

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByTestId('element-card').tagName).toBe('DIV')
  })

  it('renders the modified indicator when status is modified', () => {
    render(<ElementCardChrome status="modified" icon="font-icon-block-content" title="Block" />)

    expect(screen.getByTestId('element-card-modified-indicator')).toBeInTheDocument()
  })

  it('does not render the modified indicator when status is published', () => {
    render(<ElementCardChrome status="published" icon="font-icon-block-content" title="Block" />)

    expect(screen.queryByTestId('element-card-modified-indicator')).toBeNull()
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
