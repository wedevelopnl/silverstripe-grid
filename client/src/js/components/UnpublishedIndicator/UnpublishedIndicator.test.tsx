import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import UnpublishedIndicator from './UnpublishedIndicator'

describe('UnpublishedIndicator', () => {
  it('renders with the provided testId', () => {
    render(<UnpublishedIndicator testId="section-unpublished-indicator" />)
    expect(screen.getByTestId('section-unpublished-indicator')).toBeInTheDocument()
  })

  // "Contains", not "has": this is the container's announcement of a change
  // somewhere below it. StatusBadge is what marks a self-change.
  it('announces "contains unpublished changes" as readable text', () => {
    render(<UnpublishedIndicator testId="row-unpublished-indicator" />)

    // Text content, not aria-label: the mark has no visual form, so there is no
    // graphic for an `img` role to name — the accessible name has to *be* the
    // node's text for it to reach the accessibility tree at all.
    expect(screen.getByTestId('row-unpublished-indicator')).toHaveTextContent(
      'Contains unpublished changes',
    )
  })

  it('stays in the accessibility tree rather than being removed from it', () => {
    // The visually-hidden mixin is what keeps this readable; `display: none`
    // would drop the fact itself, not just its visual form. Guards against a
    // future switch to `hidden`/`aria-hidden` as the hiding mechanism.
    render(<UnpublishedIndicator testId="column-unpublished-indicator" />)
    const note = screen.getByTestId('column-unpublished-indicator')

    expect(note).not.toHaveAttribute('aria-hidden')
    expect(note).not.toHaveAttribute('hidden')
    expect(note).toHaveClass('ssgrid-unpublished-note')
  })
})
