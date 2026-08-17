import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import CollapseToggle from './CollapseToggle'

describe('CollapseToggle', () => {
  it('sets aria-expanded to false when collapsed', () => {
    render(
      <CollapseToggle
        isCollapsed={true}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-expanded', 'false')
  })

  it('sets aria-expanded to true when expanded', () => {
    render(
      <CollapseToggle
        isCollapsed={false}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-expanded', 'true')
  })

  it('points aria-controls at the region it toggles', () => {
    render(
      <CollapseToggle
        isCollapsed={false}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-controls', 'section-body')
  })

  it('shows "Expand" in aria-label when collapsed', () => {
    render(
      <CollapseToggle
        isCollapsed={true}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-label', 'Expand Section')
  })

  it('shows "Collapse" in aria-label when expanded', () => {
    render(
      <CollapseToggle
        isCollapsed={false}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-label', 'Collapse Section')
  })

  it('sets data-state to "collapsed" when collapsed', () => {
    render(
      <CollapseToggle
        isCollapsed={true}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('data-state', 'collapsed')
  })

  it('sets data-state to "expanded" when expanded', () => {
    render(
      <CollapseToggle
        isCollapsed={false}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('data-state', 'expanded')
  })

  it('calls onToggle when clicked', async () => {
    const user = userEvent.setup()
    const onToggle = vi.fn()

    render(
      <CollapseToggle
        isCollapsed={true}
        onToggle={onToggle}
        label="Section"
        controlsId="section-body"
      />,
    )

    await user.click(screen.getByTestId('collapse-toggle'))

    expect(onToggle).toHaveBeenCalledOnce()
  })

  it('chevron span is aria-hidden', () => {
    render(
      <CollapseToggle
        isCollapsed={false}
        onToggle={vi.fn()}
        label="Section"
        controlsId="section-body"
      />,
    )

    const chevron = screen.getByTestId('collapse-toggle').querySelector('span')
    expect(chevron).toHaveAttribute('aria-hidden', 'true')
  })
})
