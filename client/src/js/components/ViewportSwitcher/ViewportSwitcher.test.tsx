import { QueryClient } from '@tanstack/react-query'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
import * as activeViewportStore from '@/state/activeViewport'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
} from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import type { TreeApiResponse, ViewportSettings } from '@/types/elements'
import ViewportSwitcher from './ViewportSwitcher'

// Setup file configures 6 viewports in this order, default 'md'.
const VIEWPORT_KEYS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl']

function treeWithOverride(viewport: string): TreeApiResponse {
  const override: ViewportSettings = { width: 6, offset: 0, visible: true }
  const column = createColumnNode({
    gridSettings: {
      default: { width: 12, offset: 0, visible: true },
      overrides: { [viewport]: override },
    },
    children: [],
  })
  const row = createRowNode({ children: [column] })
  const section = createSectionNode({ parent: { type: 'page', id: 1 }, children: [row] })
  return createTreeApiResponse({ pageId: 1, sections: [section] })
}

function seeded(...entries: [readonly unknown[], TreeApiResponse][]) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })
  for (const [key, tree] of entries) {
    queryClient.setQueryData(key, tree)
  }
  return queryClient
}

const trigger = () => screen.getByTestId('viewport-picker-trigger')
const dropdown = () => screen.getByTestId('viewport-picker-dropdown')

describe('ViewportSwitcher', () => {
  beforeEach(() => {
    // jsdom does not implement HTMLDialogElement.showModal/close
    HTMLDialogElement.prototype.showModal = vi.fn()
    HTMLDialogElement.prototype.close = vi.fn()
  })

  it('presents the viewport control as a picker', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    expect(screen.getByTestId('viewport-switcher')).toBeInTheDocument()
    expect(trigger()).toHaveAttribute('data-viewport', 'md')
  })

  it('offers every adapter viewport', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })
    await user.click(trigger())

    expect(within(dropdown()).getAllByRole('menuitemradio')).toHaveLength(VIEWPORT_KEYS.length)
  })

  it('drives the shared active-viewport store when a viewport is chosen', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const setSpy = vi.spyOn(activeViewportStore, 'setActiveViewport')

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })
    // renderWithProviders seeds the active viewport through the same setter.
    setSpy.mockClear()

    await user.click(trigger())
    await user.click(screen.getByTestId('viewport-picker-option-lg'))

    expect(setSpy).toHaveBeenCalledWith('lg')
    expect(trigger()).toHaveAttribute('data-viewport', 'lg')

    setSpy.mockRestore()
  })

  it('marks the viewports the cached tree actually overrides', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const queryClient = seeded([queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')])

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient })
    await user.click(trigger())

    expect(
      screen
        .getByTestId('viewport-picker-option-lg')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).not.toBeNull()
    expect(
      screen
        .getByTestId('viewport-picker-option-md')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).toBeNull()
  })

  it('reads the versioned tree, not the draft, when a version is given', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    // The history viewer renders an archived version while the draft may have
    // moved on. Seed the two keys with overrides at different viewports: the
    // marks must follow the version on screen, not whatever the draft holds.
    const queryClient = seeded(
      [queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')],
      [queryKeys.elementTree.byPage(1, 'main', 7), treeWithOverride('xl')],
    )

    renderWithProviders(<ViewportSwitcher readonly version={7} />, { viewport: 'md', queryClient })
    await user.click(trigger())

    expect(
      screen
        .getByTestId('viewport-picker-option-xl')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).not.toBeNull()
    expect(
      screen
        .getByTestId('viewport-picker-option-lg')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).toBeNull()
  })

  it('offers no reset scopes when nothing is overridden', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })
    await user.click(trigger())

    expect(within(dropdown()).queryAllByRole('menuitem')).toHaveLength(0)
  })

  it('offers a reset scope for an overridden viewport', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const queryClient = seeded([queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')])

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient })
    await user.click(trigger())

    expect(
      within(dropdown()).getByRole('menuitem', { name: 'Reset Large, 1 column' }),
    ).toBeInTheDocument()
  })

  it('withholds every reset scope when readonly, keeping the viewports switchable', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const queryClient = seeded([queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')])

    renderWithProviders(<ViewportSwitcher readonly />, { viewport: 'md', queryClient })
    await user.click(trigger())

    expect(within(dropdown()).queryAllByRole('menuitem')).toHaveLength(0)
    expect(within(dropdown()).getAllByRole('menuitemradio')).toHaveLength(VIEWPORT_KEYS.length)
    expect(screen.queryByTestId('confirm-dialog')).not.toBeInTheDocument()
  })

  it('confirms before running a reset chosen from the menu', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const queryClient = seeded([queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')])

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient })
    await user.click(trigger())
    await user.click(screen.getByRole('menuitem', { name: 'Reset Large, 1 column' }))

    expect(screen.getByTestId('confirm-dialog')).toBeInTheDocument()
    expect(screen.getByText('Reset Large overrides')).toBeInTheDocument()
    expect(screen.getByText('Reset overrides for 1 column on Large?')).toBeInTheDocument()
  })

  it('dismisses the dialog on cancel without resetting', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const queryClient = seeded([queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg')])

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient })
    await user.click(trigger())
    await user.click(screen.getByRole('menuitem', { name: 'Reset Large, 1 column' }))
    await user.click(screen.getByText('Cancel'))

    expect(screen.queryByTestId('confirm-dialog')).not.toBeInTheDocument()
  })
})
