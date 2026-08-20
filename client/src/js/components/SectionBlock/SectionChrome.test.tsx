import { render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { type ChromeContractOverrides, describeChromeContract } from '@/testing/chromeContract'
import SectionChrome from './SectionChrome'

const baseProps = {
  status: 'published' as const,
  hasUnpublishedDescendant: false,
  title: 'Hero Section',
  isCollapsed: false,
  onToggle: vi.fn(),
  children: <span data-testid="body-slot" />,
}

describe('SectionChrome', () => {
  describeChromeContract({
    prefix: 'section',
    title: 'Hero Section',
    renderChrome: (overrides: ChromeContractOverrides = {}) =>
      render(<SectionChrome {...baseProps} {...overrides} />),
  })

  it('renders the frame, header and title', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.getByTestId('section-block')).toBeInTheDocument()
    expect(screen.getByTestId('section-header')).toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section')
  })

  it('renders children inside the section body', () => {
    render(<SectionChrome {...baseProps} />)

    expect(screen.getByTestId('section-body')).toContainElement(screen.getByTestId('body-slot'))
  })

  it('wires the collapse toggle to the body it controls', () => {
    render(<SectionChrome {...baseProps} />)

    const controlsId = screen.getByTestId('collapse-toggle').getAttribute('aria-controls')

    expect(controlsId).not.toBeNull()
    expect(screen.getByTestId('section-body')).toHaveAttribute('id', controlsId)
  })
})
