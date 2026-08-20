import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSimpleElement } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import ElementActions from './ElementActions'

beforeEach(() => {
  mockFetchSuccess({})
})

const originalLocation = window.location

afterEach(() => {
  // Restore the real jsdom location if a test swapped it out.
  if (window.location !== originalLocation) {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    })
  }
})

/**
 * jsdom's `window.location.assign` is non-configurable, so it can't be spied
 * directly. Replace the whole `location` with a stub exposing a mock `assign`
 * (`afterEach` restores the original). Returns the mock for assertions.
 */
function stubLocationAssign(): ReturnType<typeof vi.fn> {
  const assign = vi.fn()
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...originalLocation, assign },
  })
  return assign
}

describe('ElementActions', () => {
  it('renders the action toolbar with duplicate/archive enabled when permitted', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({
      canDelete: true,
      canCreate: true,
    })

    renderWithProviders(<ElementActions node={node} />)

    const toolbar = screen.getByTestId('element-toolbar')
    expect(toolbar).toBeInTheDocument()
    // Screen readers announce the toolbar landmark; mirrors ViewportSwitcher.
    expect(toolbar).toHaveAttribute('role', 'toolbar')
    expect(toolbar).toHaveAccessibleName('Element actions')
    expect(screen.getByTestId('element-action-duplicate')).toBeEnabled()
    expect(screen.getByTestId('element-action-archive')).toBeEnabled()
    // History links to the element's CMS edit form, so it follows `editLink`,
    // which the factory populates by default.
    expect(screen.getByTestId('element-action-history')).toBeEnabled()

    // "Duplicate to page" has no toolbar glyph, so it lives in the overflow menu.
    await user.click(screen.getByTestId('actions-menu-trigger'))
    expect(screen.getByText(/duplicate to/i)).toBeInTheDocument()
  })

  it('disables the history action when the element has no CMS edit link', () => {
    const node = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-action-history')).toBeDisabled()
  })

  it('renders the toolbar without overflow menu and with disabled actions when not permitted', () => {
    const node = createSimpleElement({
      canDelete: false,
      canCreate: false,
    })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-toolbar')).toBeInTheDocument()
    expect(screen.getByTestId('element-action-duplicate')).toBeDisabled()
    expect(screen.getByTestId('element-action-archive')).toBeDisabled()
    // No overflow menu: with no permitted actions there is nothing to surface.
    expect(screen.queryByTestId('actions-menu-trigger')).not.toBeInTheDocument()
  })

  it('wires the toolbar fold icon to the supplied collapse control', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: true, canCreate: true })
    let toggled = 0

    renderWithProviders(
      <ElementActions
        node={node}
        collapse={{
          isCollapsed: false,
          onToggle: () => {
            toggled += 1
          },
          label: 'My block',
        }}
      />,
    )

    const fold = screen.getByTestId('element-action-collapse')
    expect(fold).toBeEnabled()
    await user.click(fold)
    expect(toggled).toBe(1)
  })

  it('renders only the overflow menu in kebab-only mode (column header)', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} kebabOnly />)

    expect(screen.queryByTestId('element-toolbar')).not.toBeInTheDocument()
    expect(screen.getByTestId('actions-menu-trigger')).toBeInTheDocument()
  })

  it('surfaces the whole permitted action set in the kebab-only overflow menu', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} kebabOnly />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    // `kebabOnly` is a change of presentation, not of capability: the menu
    // offers exactly what the icon row would, in the same order. This used to
    // carry only duplicate/duplicate-to/archive, which was survivable for
    // columns (their title links to the edit form) but would have silently
    // dropped history, open and edit from any element card folded by width.
    const menu = screen.getByRole('menu')
    expect([...menu.querySelectorAll('[role="menuitem"]')].map((i) => i.textContent)).toEqual([
      'View history',
      'Duplicate',
      'Open in a new tab',
      'Edit',
      'Archive',
      'Duplicate to…',
    ])
  })

  it('omits archive from the kebab-only menu when the element cannot be deleted', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: false, canCreate: true })

    renderWithProviders(<ElementActions node={node} kebabOnly />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    expect(screen.queryByText('Archive', { selector: '[role="menuitem"]' })).not.toBeInTheDocument()
    // Duplicate and Duplicate-to remain.
    expect(screen.getByText('Duplicate', { selector: '[role="menuitem"]' })).toBeInTheDocument()
    expect(screen.getByText(/duplicate to/i)).toBeInTheDocument()
  })

  it('omits the duplicate actions from the kebab-only menu when the element cannot be created', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: true, canCreate: false })

    renderWithProviders(<ElementActions node={node} kebabOnly />)

    await user.click(screen.getByTestId('actions-menu-trigger'))

    expect(
      screen.queryByText('Duplicate', { selector: '[role="menuitem"]' }),
    ).not.toBeInTheDocument()
    expect(screen.queryByText(/duplicate to/i)).not.toBeInTheDocument()
    // Only Archive remains.
    expect(screen.getByText('Archive', { selector: '[role="menuitem"]' })).toBeInTheDocument()
  })

  it('labels every toolbar button with its visible action name', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-action-history')).toHaveAttribute('title', 'View history')
    expect(screen.getByTestId('element-action-history')).toHaveAccessibleName('View history')
    expect(screen.getByTestId('element-action-duplicate')).toHaveAttribute('title', 'Duplicate')
    expect(screen.getByTestId('element-action-open')).toHaveAttribute('title', 'Open in a new tab')
    expect(screen.getByTestId('element-action-edit')).toHaveAttribute('title', 'Edit')
    expect(screen.getByTestId('element-action-archive')).toHaveAttribute('title', 'Archive')
  })

  it('marks only the archive button as destructive', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    // The trash action is flagged destructive so styling/confirmation can key off it.
    expect(screen.getByTestId('element-action-archive')).toHaveAttribute('data-destructive', 'true')
    // A non-destructive button carries no such flag.
    expect(screen.getByTestId('element-action-duplicate')).not.toHaveAttribute('data-destructive')
  })

  it('labels the fold icon "Collapse" with no title when no collapse control is supplied', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    const fold = screen.getByTestId('element-action-collapse')
    expect(fold).toHaveAttribute('title', 'Collapse')
    expect(fold).toBeDisabled()
  })

  it('labels the fold icon to collapse the named block when it is expanded', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(
      <ElementActions
        node={node}
        collapse={{ isCollapsed: false, onToggle: () => {}, label: 'My block' }}
      />,
    )

    const fold = screen.getByTestId('element-action-collapse')
    // Expanded state offers the "collapse" affordance, interpolating the block title.
    expect(fold).toHaveAttribute('title', 'Collapse My block')
    expect(fold).toBeEnabled()
  })

  it('labels the fold icon to expand the named block when it is collapsed', () => {
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(
      <ElementActions
        node={node}
        collapse={{ isCollapsed: true, onToggle: () => {}, label: 'My block' }}
      />,
    )

    const fold = screen.getByTestId('element-action-collapse')
    // Collapsed state offers the "expand" affordance instead.
    expect(fold).toHaveAttribute('title', 'Expand My block')
  })

  it('navigates to the element history tab when the history action is clicked', async () => {
    const user = userEvent.setup()
    const assign = stubLocationAssign()

    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    await user.click(screen.getByTestId('element-action-history'))

    expect(assign).toHaveBeenCalledWith(`${node.editLink}#Root_History`)
  })

  it('does not navigate to history when the element has no edit link', async () => {
    const user = userEvent.setup()
    const assign = stubLocationAssign()

    const node = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementActions node={node} />)

    const history = screen.getByTestId('element-action-history')
    expect(history).toBeDisabled()
    await user.click(history)
    expect(assign).not.toHaveBeenCalled()
  })

  it('navigates to the element edit form when the edit action is clicked', async () => {
    const user = userEvent.setup()
    const assign = stubLocationAssign()

    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    await user.click(screen.getByTestId('element-action-edit'))

    expect(assign).toHaveBeenCalledWith(node.editLink)
  })

  it('disables the edit action when the element has no edit link', () => {
    const node = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-action-edit')).toBeDisabled()
  })

  it('opens the element edit form in a new tab when the open action is clicked', async () => {
    const user = userEvent.setup()
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)

    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    await user.click(screen.getByTestId('element-action-open'))

    expect(open).toHaveBeenCalledWith(node.editLink, '_blank', 'noopener,noreferrer')
  })

  it('disables the open-in-new-tab action when the element has no edit link', () => {
    const node = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-action-open')).toBeDisabled()
  })

  it('opens the archive confirmation dialog from the toolbar with the Archive confirm label', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: true, canCreate: true, title: 'Hero' })

    renderWithProviders(<ElementActions node={node} />)

    // No dialog before the archive button is pressed.
    expect(screen.queryByTestId('confirm-dialog')).not.toBeInTheDocument()

    await user.click(screen.getByTestId('element-action-archive'))

    const dialog = screen.getByTestId('confirm-dialog')
    expect(dialog).toBeInTheDocument()
    // The confirm button (scoped to the dialog, distinct from the toolbar's
    // archive button) carries the toolbar-supplied "Archive" confirm label.
    expect(within(dialog).getByRole('button', { name: 'Archive' })).toBeInTheDocument()
  })

  it('opens the duplicate-to dialog from the overflow menu', async () => {
    const user = userEvent.setup()
    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.queryByTestId('duplicate-to-dialog')).not.toBeInTheDocument()

    await user.click(screen.getByTestId('actions-menu-trigger'))
    await user.click(screen.getByText(/duplicate to/i))

    expect(screen.getByTestId('duplicate-to-dialog')).toBeInTheDocument()
  })

  describe('toolbar roving tabindex', () => {
    function enabledToolbarButtons(): HTMLButtonElement[] {
      return Array.from(
        screen
          .getByTestId('element-toolbar')
          .querySelectorAll<HTMLButtonElement>('button:not(:disabled)'),
      )
    }

    it('exposes the toolbar as a single tab stop', () => {
      renderWithProviders(
        <ElementActions node={createSimpleElement({ canDelete: true, canCreate: true })} />,
      )

      const tabbable = enabledToolbarButtons().filter((b) => b.tabIndex === 0)

      expect(tabbable).toHaveLength(1)
      // The first enabled control holds the tab stop until an arrow moves it.
      expect(tabbable[0]).toBe(enabledToolbarButtons()[0])
    })

    it('moves focus and the tab stop with ArrowRight and ArrowLeft', async () => {
      const user = userEvent.setup()
      renderWithProviders(
        <ElementActions node={createSimpleElement({ canDelete: true, canCreate: true })} />,
      )

      const buttons = enabledToolbarButtons()
      buttons[0].focus()

      await user.keyboard('{ArrowRight}')
      expect(buttons[1]).toHaveFocus()
      expect(buttons[1].tabIndex).toBe(0)
      expect(buttons[0].tabIndex).toBe(-1)

      await user.keyboard('{ArrowRight}')
      expect(buttons[2]).toHaveFocus()

      // From index 2, so a decrement is distinguishable from a jump to first.
      await user.keyboard('{ArrowLeft}')
      expect(buttons[1]).toHaveFocus()
      expect(buttons[1].tabIndex).toBe(0)

      await user.keyboard('{ArrowLeft}')
      expect(buttons[0]).toHaveFocus()
      expect(buttons[0].tabIndex).toBe(0)
    })

    it('clamps at both ends rather than wrapping', async () => {
      const user = userEvent.setup()
      renderWithProviders(
        <ElementActions node={createSimpleElement({ canDelete: true, canCreate: true })} />,
      )

      const buttons = enabledToolbarButtons()
      const last = buttons.length - 1
      buttons[0].focus()

      await user.keyboard('{ArrowLeft}')
      expect(buttons[0]).toHaveFocus()

      await user.keyboard('{End}')
      expect(buttons[last]).toHaveFocus()

      await user.keyboard('{ArrowRight}')
      expect(buttons[last]).toHaveFocus()

      await user.keyboard('{Home}')
      expect(buttons[0]).toHaveFocus()
    })

    it('reaches the overflow trigger as the last stop', async () => {
      const user = userEvent.setup()
      renderWithProviders(
        <ElementActions node={createSimpleElement({ canDelete: true, canCreate: true })} />,
      )

      enabledToolbarButtons()[0].focus()
      await user.keyboard('{End}')

      const trigger = screen.getByTestId('actions-menu-trigger')
      expect(trigger).toHaveFocus()
      // The tab stop has to travel with focus. Focus moves imperatively, so
      // without this the trigger could hold tabIndex -1 alongside every icon
      // button and the whole toolbar would drop out of the tab order.
      expect(trigger).toHaveAttribute('tabindex', '0')
      expect(enabledToolbarButtons().filter((b) => b.tabIndex === 0)).toHaveLength(1)
    })

    it('leaves the open overflow menu to handle its own Home/End', async () => {
      const user = userEvent.setup()
      renderWithProviders(
        <ElementActions node={createSimpleElement({ canDelete: true, canCreate: true })} />,
      )

      const trigger = screen.getByTestId('actions-menu-trigger')
      await user.click(trigger)

      // Focus sits on the menu container, not a toolbar button; the toolbar
      // must not steal the key and yank focus back to a toolbar control.
      await user.keyboard('{Home}')

      // Focus staying on the menu is the assertion that pins the guard: the
      // dropdown outliving Home either way, and the trigger not holding focus
      // while the menu does, are both true when the toolbar has stolen it.
      expect(screen.getByTestId('actions-menu-dropdown')).toHaveFocus()
      expect(trigger).not.toHaveFocus()
    })
  })
})
