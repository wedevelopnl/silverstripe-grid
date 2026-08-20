import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { ComponentProps } from 'react'
import CollapseToggle from './CollapseToggle'

function renderToggle(overrides: Partial<ComponentProps<typeof CollapseToggle>> = {}) {
  return render(
    <CollapseToggle
      isCollapsed={true}
      onToggle={vi.fn()}
      label="Section"
      controlsId="section-body"
      {...overrides}
    />,
  )
}

describe('CollapseToggle', () => {
  it('sets aria-expanded to false when collapsed', () => {
    renderToggle({ isCollapsed: true })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-expanded', 'false')
  })

  it('sets aria-expanded to true when expanded', () => {
    renderToggle({ isCollapsed: false })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-expanded', 'true')
  })

  it('points aria-controls at the region it toggles', () => {
    renderToggle({ isCollapsed: false })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-controls', 'section-body')
  })

  it('shows "Expand" in aria-label when collapsed', () => {
    renderToggle({ isCollapsed: true })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-label', 'Expand Section')
  })

  it('shows "Collapse" in aria-label when expanded', () => {
    renderToggle({ isCollapsed: false })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('aria-label', 'Collapse Section')
  })

  it('sets data-state to "collapsed" when collapsed', () => {
    renderToggle({ isCollapsed: true })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('data-state', 'collapsed')
  })

  it('sets data-state to "expanded" when expanded', () => {
    renderToggle({ isCollapsed: false })

    expect(screen.getByTestId('collapse-toggle')).toHaveAttribute('data-state', 'expanded')
  })

  it('calls onToggle when clicked', async () => {
    const user = userEvent.setup()
    const onToggle = vi.fn()

    renderToggle({ isCollapsed: true, onToggle })

    await user.click(screen.getByTestId('collapse-toggle'))

    expect(onToggle).toHaveBeenCalledOnce()
  })

  it('chevron span is aria-hidden', () => {
    renderToggle({ isCollapsed: false })

    const chevron = screen.getByTestId('collapse-toggle').querySelector('span')
    expect(chevron).toHaveAttribute('aria-hidden', 'true')
  })
})
