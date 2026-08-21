import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import PlacementMenu from './PlacementMenu'

function renderMenu(onSelect = vi.fn()) {
  render(
    <PlacementMenu
      triggerLabel="More ways to add a row"
      itemLabel="Place shared row…"
      onSelect={onSelect}
      variant="strip"
      testId="placement-menu"
    />,
  )
  return onSelect
}

describe('PlacementMenu', () => {
  it('keeps the menu closed until the caret is clicked', () => {
    renderMenu()

    expect(screen.getByTestId('placement-menu-trigger')).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('names the caret for screen readers without exposing a visible label', () => {
    renderMenu()

    expect(screen.getByTestId('placement-menu-trigger')).toHaveAccessibleName(
      'More ways to add a row',
    )
  })

  it('opens on click and shows the single route', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(screen.getByTestId('placement-menu-trigger'))

    expect(screen.getByTestId('placement-menu-trigger')).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByRole('menuitem', { name: 'Place shared row…' })).toBeInTheDocument()
  })

  it('invokes onSelect and closes when the item is clicked', async () => {
    const user = userEvent.setup()
    const onSelect = renderMenu()

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Place shared row…' }))

    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('closes on Escape without selecting', async () => {
    const user = userEvent.setup()
    const onSelect = renderMenu()

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(onSelect).not.toHaveBeenCalled()
  })

  it('activates the item from the keyboard', async () => {
    const user = userEvent.setup()
    const onSelect = renderMenu()

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.keyboard('{Enter}')

    expect(onSelect).toHaveBeenCalledTimes(1)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('renders no icon in the menu item — every editor flyout is text-only', async () => {
    const user = userEvent.setup()
    renderMenu()

    await user.click(screen.getByTestId('placement-menu-trigger'))

    const item = screen.getByRole('menuitem', { name: 'Place shared row…' })
    expect(item.querySelector('[class*="font-icon"]')).toBeNull()
    expect(item).toHaveTextContent('Place shared row…')
  })

  it('marks the variant so the two scales can be styled apart', () => {
    renderMenu()

    expect(screen.getByTestId('placement-menu')).toHaveAttribute('data-variant', 'strip')
  })
})
