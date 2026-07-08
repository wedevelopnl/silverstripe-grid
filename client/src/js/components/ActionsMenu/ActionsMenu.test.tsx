import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import ActionsMenu, { type ActionItem } from './ActionsMenu'

function createActions(overrides: Partial<ActionItem>[] = []): ActionItem[] {
  const defaults: ActionItem[] = [
    { key: 'edit', label: 'Edit', onAction: vi.fn() },
    { key: 'delete', label: 'Delete', destructive: true, onAction: vi.fn() },
  ]

  return defaults.map((action, index) => ({
    ...action,
    ...overrides[index],
  }))
}

describe('ActionsMenu', () => {
  it('returns null when actions array is empty', () => {
    const { container } = render(<ActionsMenu actions={[]} />)

    expect(container.innerHTML).toBe('')
  })

  it('opens menu on trigger click', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    expect(screen.getByRole('menu')).toBeInTheDocument()
  })

  it('closes menu on second trigger click', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    const trigger = screen.getByTestId('actions-menu-trigger')

    await user.click(trigger)
    expect(screen.getByRole('menu')).toBeInTheDocument()

    await user.click(trigger)
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('closes on outside click', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))
    expect(screen.getByRole('menu')).toBeInTheDocument()

    await user.click(document.body)

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('closes on Escape key', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))
    expect(screen.getByRole('menu')).toBeInTheDocument()

    await user.keyboard('{Escape}')

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('fires action callback on menu item click', async () => {
    const user = userEvent.setup()
    const onAction = vi.fn()
    const actions = createActions([{ onAction }])

    render(<ActionsMenu actions={actions} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))
    await user.click(screen.getByText('Edit'))

    expect(onAction).toHaveBeenCalledOnce()
  })

  it('fires action callback on Enter key on menu item', async () => {
    const user = userEvent.setup()
    const onAction = vi.fn()
    const actions = createActions([{ onAction }])

    render(<ActionsMenu actions={actions} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    screen.getByText('Edit').focus()
    await user.keyboard('{Enter}')

    expect(onAction).toHaveBeenCalledOnce()
  })

  it('fires action callback on Space key on menu item', async () => {
    const user = userEvent.setup()
    const onAction = vi.fn()
    const actions = createActions([{ onAction }])

    render(<ActionsMenu actions={actions} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    screen.getByText('Edit').focus()
    await user.keyboard(' ')

    expect(onAction).toHaveBeenCalledOnce()
  })

  it('restores focus to the trigger after activating an item with Enter', async () => {
    const user = userEvent.setup()
    const actions = createActions([{ onAction: vi.fn() }])

    render(<ActionsMenu actions={actions} />)

    const trigger = screen.getByTestId('actions-menu-trigger')
    await user.click(trigger)
    screen.getByText('Edit').focus()
    await user.keyboard('{Enter}')

    // Focus must return to the trigger, not fall to <body> (W3C APG menu-button).
    expect(document.activeElement).toBe(trigger)
  })

  it('restores focus to the trigger after clicking an item', async () => {
    const user = userEvent.setup()
    const actions = createActions([{ onAction: vi.fn() }])

    render(<ActionsMenu actions={actions} />)

    const trigger = screen.getByTestId('actions-menu-trigger')
    await user.click(trigger)
    await user.click(screen.getByText('Edit'))

    expect(document.activeElement).toBe(trigger)
  })

  it('closes menu after action fires', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))
    await user.click(screen.getByText('Edit'))

    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('applies destructive marker to destructive actions', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    const deleteItem = screen.getByText('Delete')

    expect(deleteItem).toHaveAttribute('data-destructive', 'true')
    expect(screen.getByText('Edit')).not.toHaveAttribute('data-destructive')
  })

  it('toggles aria-expanded with menu state', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    const trigger = screen.getByTestId('actions-menu-trigger')

    expect(trigger).toHaveAttribute('aria-expanded', 'false')

    await user.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'true')

    await user.click(trigger)
    expect(trigger).toHaveAttribute('aria-expanded', 'false')
  })

  it('trigger has aria-controls pointing to menu id when open', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    const trigger = screen.getByTestId('actions-menu-trigger')

    // Closed: no aria-controls
    expect(trigger).not.toHaveAttribute('aria-controls')

    await user.click(trigger)

    // Open: aria-controls references the dropdown id
    expect(trigger).toHaveAttribute('aria-controls', 'actions-menu-menu')
  })

  it('trigger has aria-haspopup="menu"', () => {
    render(<ActionsMenu actions={createActions()} />)

    expect(screen.getByTestId('actions-menu-trigger')).toHaveAttribute('aria-haspopup', 'menu')
  })

  it('trigger has aria-label "Actions"', () => {
    render(<ActionsMenu actions={createActions()} />)

    expect(screen.getByTestId('actions-menu-trigger')).toHaveAttribute('aria-label', 'Actions')
  })

  it('dropdown has role="menu"', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    const dropdown = screen.getByTestId('actions-menu-dropdown')
    expect(dropdown).toHaveAttribute('role', 'menu')
  })

  it('menu items have role="menuitem"', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    const items = screen.getAllByRole('menuitem')
    expect(items).toHaveLength(2)
  })

  it('non-destructive item does not have destructive marker', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    expect(screen.getByText('Edit')).not.toHaveAttribute('data-destructive')
  })

  it('uses custom testId for dropdown', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} testId="custom-menu" />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    expect(screen.getByTestId('custom-menu-dropdown')).toBeInTheDocument()
  })

  it('outside click while menu is closed does not open the menu', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    // Menu starts closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    // Click document body while closed
    await user.click(document.body)

    // Menu should still be closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('Escape key while menu is closed does not cause errors', async () => {
    const user = userEvent.setup()

    render(<ActionsMenu actions={createActions()} />)

    // Menu starts closed
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()

    // Press Escape while closed
    await user.keyboard('{Escape}')

    // Menu should still be closed, no errors
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
  })

  it('Escape while menu is closed does not steal focus to the trigger', async () => {
    // Pins the `if (!isOpen) return` guard at ActionsMenu.tsx:58 — the document
    // keydown handler must NOT be registered while the menu is closed. Without
    // the guard, a global Escape would run close()+trigger.focus(), grabbing
    // focus onto a trigger the user never opened.
    const user = userEvent.setup()

    render(
      <>
        <button type="button" data-testid="outside-button">
          Outside
        </button>
        <ActionsMenu actions={createActions()} />
      </>,
    )

    const outside = screen.getByTestId('outside-button')
    outside.focus()
    expect(document.activeElement).toBe(outside)

    await user.keyboard('{Escape}')

    // Focus must remain on the outside button, not jump to the menu trigger.
    expect(document.activeElement).toBe(outside)
  })

  describe('roving tabindex and focus management', () => {
    it('sets roving tabindex with exactly one menuitem tab-reachable on open', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      const items = screen.getAllByRole('menuitem')
      const reachable = items.filter((i) => i.tabIndex === 0)
      expect(reachable).toHaveLength(1)
    })

    it('sets aria-activedescendant on the menu pointing to the active item', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[0].id)
      expect(items[0].id).toBeTruthy()
    })

    it('ArrowDown moves active item and updates aria-activedescendant', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{ArrowDown}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[1].id)
      expect(items[1].tabIndex).toBe(0)
      expect(items[0].tabIndex).toBe(-1)
    })

    it('ArrowUp moves active item backward', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{ArrowDown}{ArrowUp}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[0].id)
    })

    it('Home/End jump active item to first/last', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{End}')
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[items.length - 1].id)

      await user.keyboard('{Home}')
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[0].id)
    })

    it('Enter fires the active item action', async () => {
      const user = userEvent.setup()
      const onAction = vi.fn()
      const actions = createActions([{}, { onAction }])

      render(<ActionsMenu actions={actions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      await user.keyboard('{ArrowDown}{Enter}')

      expect(onAction).toHaveBeenCalledOnce()
    })

    it('clamps active item when the actions list shrinks while open', async () => {
      const user = userEvent.setup()

      const threeActions: ActionItem[] = [
        { key: 'a', label: 'Alpha', onAction: vi.fn() },
        { key: 'b', label: 'Beta', onAction: vi.fn() },
        { key: 'c', label: 'Gamma', onAction: vi.fn() },
      ]

      const { rerender } = render(<ActionsMenu actions={threeActions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      // Move active to the last (index 2) item.
      await user.keyboard('{End}')

      const menu = screen.getByRole('menu')
      const lastId = screen.getAllByRole('menuitem')[2].id
      expect(menu.getAttribute('aria-activedescendant')).toBe(lastId)

      // Shrink the list to a single action — index 2 is now stale.
      rerender(<ActionsMenu actions={[threeActions[0]]} />)

      const remaining = screen.getAllByRole('menuitem')
      expect(remaining).toHaveLength(1)

      // aria-activedescendant must reference the surviving item, not a dead id.
      const activeId = menu.getAttribute('aria-activedescendant')
      expect(activeId).toBe(remaining[0].id)
      expect(within(menu).queryByText('Alpha')).toBeInTheDocument()
    })

    it('Enter fires the surviving action after the list shrinks past the active index', async () => {
      const user = userEvent.setup()
      const onAlpha = vi.fn()

      const threeActions: ActionItem[] = [
        { key: 'a', label: 'Alpha', onAction: onAlpha },
        { key: 'b', label: 'Beta', onAction: vi.fn() },
        { key: 'c', label: 'Gamma', onAction: vi.fn() },
      ]

      const { rerender } = render(<ActionsMenu actions={threeActions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      await user.keyboard('{End}')

      rerender(<ActionsMenu actions={[threeActions[0]]} />)

      await user.keyboard('{Enter}')

      expect(onAlpha).toHaveBeenCalledOnce()
    })

    it('Escape returns focus to the trigger', async () => {
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      const trigger = screen.getByTestId('actions-menu-trigger')
      await user.click(trigger)
      await user.keyboard('{Escape}')

      expect(document.activeElement).toBe(trigger)
    })

    it('preserves the active index when the actions list grows', async () => {
      // Pins the clamp at ActionsMenu.tsx:41 — Math.min(i, max(0, len-1)) must
      // keep the live index `i` when the list is large enough to hold it, not
      // collapse it to 0. Dropping `i` (=> Math.min(0, len-1)) would reset to 0.
      const user = userEvent.setup()

      const twoActions: ActionItem[] = [
        { key: 'a', label: 'Alpha', onAction: vi.fn() },
        { key: 'b', label: 'Beta', onAction: vi.fn() },
      ]

      const { rerender } = render(<ActionsMenu actions={twoActions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')

      // Move active to the second item.
      await user.keyboard('{ArrowDown}')
      expect(menu.getAttribute('aria-activedescendant')).toBe(screen.getAllByRole('menuitem')[1].id)

      // Grow the list — index 1 is still valid and must be preserved.
      rerender(
        <ActionsMenu actions={[...twoActions, { key: 'c', label: 'Gamma', onAction: vi.fn() }]} />,
      )

      const items = screen.getAllByRole('menuitem')
      expect(items).toHaveLength(3)
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[1].id)
    })

    it('ArrowDown clamps at the last item instead of running past the end', async () => {
      // Pins the boundary at ActionsMenu.tsx:96 (i >= last). Jump to the last
      // item with End, then press ArrowDown ONCE: the active item must stay on
      // the last id. The `i > last` mutant computes last + 1 on this single
      // press (dead aria-activedescendant). A multi-press sequence would mask
      // the mutant — it overshoots to last + 1 then clamps back on the next
      // press, so it must be exactly one press from the last item.
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{End}')
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[items.length - 1].id)

      await user.keyboard('{ArrowDown}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[items.length - 1].id)
    })

    it('ArrowDown steps to the next item rather than jumping straight to the last', async () => {
      // Pins the increment branch at ActionsMenu.tsx:96. With three items, one
      // ArrowDown from index 0 must land on index 1 (i + 1), not on the last
      // index 2 (which a "=> last" collapse of the conditional would produce).
      const user = userEvent.setup()

      const threeActions: ActionItem[] = [
        { key: 'a', label: 'Alpha', onAction: vi.fn() },
        { key: 'b', label: 'Beta', onAction: vi.fn() },
        { key: 'c', label: 'Gamma', onAction: vi.fn() },
      ]

      render(<ActionsMenu actions={threeActions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{ArrowDown}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[1].id)
    })

    it('ArrowUp steps to the previous item rather than jumping straight to the first', async () => {
      // Pins the decrement branch at ActionsMenu.tsx:100. With three items, from
      // the last item one ArrowUp must land on index 1 (i - 1), not on index 0
      // (which a "=> 0" collapse of the conditional would produce).
      const user = userEvent.setup()

      const threeActions: ActionItem[] = [
        { key: 'a', label: 'Alpha', onAction: vi.fn() },
        { key: 'b', label: 'Beta', onAction: vi.fn() },
        { key: 'c', label: 'Gamma', onAction: vi.fn() },
      ]

      render(<ActionsMenu actions={threeActions} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      await user.keyboard('{End}')
      expect(menu.getAttribute('aria-activedescendant')).toBe(items[2].id)

      await user.keyboard('{ArrowUp}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[1].id)
    })

    it('ArrowUp clamps at the first item instead of running below zero', async () => {
      // Pins the boundary at ActionsMenu.tsx:100 (i <= 0). Pressing ArrowUp while
      // already on the first item must stay at index 0; `i < 0` would decrement
      // to -1 on this single press (dead aria-activedescendant). A second press
      // would clamp -1 back to 0 and mask the mutant, so press exactly ONCE
      // from the first item.
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))
      const menu = screen.getByRole('menu')
      const items = screen.getAllByRole('menuitem')

      // Already at index 0 on open; a single ArrowUp must not move below it.
      await user.keyboard('{ArrowUp}')

      expect(menu.getAttribute('aria-activedescendant')).toBe(items[0].id)
    })

    it('keeps the menu container out of the sequential tab order', async () => {
      // Pins the menu container tabIndex at ActionsMenu.tsx:153. The roving
      // pattern focuses the menu programmatically (menuRef.focus) but the
      // container must NOT be in the natural tab sequence — tabIndex must be -1.
      // The UnaryOperator mutant (-1 => +1) makes it tabIndex 1, dragging the
      // container into (and reordering) the tab sequence.
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      expect(screen.getByRole('menu').tabIndex).toBe(-1)
    })

    it('inactive menu items are removed from the tab order', async () => {
      // Pins the roving tabindex at ActionsMenu.tsx:165/153: inactive items get
      // tabIndex -1. The UnaryOperator mutant (-1 => +1) would make them
      // tab-reachable, breaking the single-tab-stop roving pattern.
      const user = userEvent.setup()

      render(<ActionsMenu actions={createActions()} />)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      const items = screen.getAllByRole('menuitem')
      const inactive = items.filter(
        (item) => item.id !== screen.getByRole('menu').getAttribute('aria-activedescendant'),
      )

      expect(inactive.length).toBeGreaterThan(0)
      for (const item of inactive) {
        expect(item.tabIndex).toBe(-1)
      }
    })
  })
})
