import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { createSimpleElement } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import ElementActions from './ElementActions'

describe('ElementActions', () => {
  it('renders the action toolbar with duplicate/archive enabled when permitted', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

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
    mockFetchSuccess({})

    const node = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementActions node={node} />)

    expect(screen.getByTestId('element-action-history')).toBeDisabled()
  })

  it('renders the toolbar without overflow menu and with disabled actions when not permitted', () => {
    mockFetchSuccess({})

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
    mockFetchSuccess({})

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
    mockFetchSuccess({})

    const node = createSimpleElement({ canDelete: true, canCreate: true })

    renderWithProviders(<ElementActions node={node} kebabOnly />)

    expect(screen.queryByTestId('element-toolbar')).not.toBeInTheDocument()
    expect(screen.getByTestId('actions-menu-trigger')).toBeInTheDocument()
  })
})
