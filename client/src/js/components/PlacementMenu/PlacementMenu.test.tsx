import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import PlacementMenu from './PlacementMenu'

function renderMenu(onSelect = vi.fn()) {
  render(
    <PlacementMenu
      triggerLabel="More ways to add a row"
      items={[{ key: 'shared', label: 'Place shared row…', onSelect }]}
      variant="strip"
      testId="placement-menu"
    />,
  )
  return onSelect
}

function renderMultiMenu(onSelect: (key: string) => void) {
  render(
    <PlacementMenu
      triggerLabel="More ways to add a block"
      items={[
        { key: 'row', label: 'Row', onSelect: () => onSelect('row') },
        { key: 'column', label: 'Column', onSelect: () => onSelect('column') },
        { key: 'element', label: 'Content element…', onSelect: () => onSelect('element') },
      ]}
      variant="strip"
      testId="placement-menu"
    />,
  )
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

  it('lists every route in order', async () => {
    const user = userEvent.setup()
    renderMultiMenu(vi.fn())

    await user.click(screen.getByTestId('placement-menu-trigger'))

    expect(screen.getAllByRole('menuitem').map((item) => item.textContent)).toEqual([
      'Row',
      'Column',
      'Content element…',
    ])
  })

  it('invokes the clicked route, not the first one', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    renderMultiMenu(onSelect)

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Content element…' }))

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('element')
  })

  it('activates the arrowed-to route from the keyboard', async () => {
    const user = userEvent.setup()
    const onSelect = vi.fn()
    renderMultiMenu(onSelect)

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.keyboard('{ArrowDown}{Enter}')

    expect(onSelect).toHaveBeenCalledExactlyOnceWith('column')
  })

  it('tracks the arrowed-to route in aria-activedescendant', async () => {
    const user = userEvent.setup()
    renderMultiMenu(vi.fn())

    await user.click(screen.getByTestId('placement-menu-trigger'))
    await user.keyboard('{ArrowDown}')

    const menu = screen.getByRole('menu')
    const active = screen.getByRole('menuitem', { name: 'Column' })
    expect(menu).toHaveAttribute('aria-activedescendant', active.id)
  })
})
