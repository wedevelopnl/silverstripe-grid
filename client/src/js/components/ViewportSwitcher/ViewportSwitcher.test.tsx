import { QueryClient } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
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

// Setup file (client/src/js/testing/setup.ts) configures 6 viewports in this order.
const VIEWPORT_KEYS = ['xs', 'sm', 'md', 'lg', 'xl', 'xxl']

const viewportButtons = () =>
  VIEWPORT_KEYS.map((key) => screen.getByTestId(`viewport-button-${key}`))

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

describe('ViewportSwitcher', () => {
  beforeEach(() => {
    // jsdom does not implement HTMLDialogElement.showModal/close
    HTMLDialogElement.prototype.showModal = vi.fn()
    HTMLDialogElement.prototype.close = vi.fn()
  })

  it('renders buttons for all viewports', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />)

    const buttons = viewportButtons()

    expect(buttons).toHaveLength(6)
    expect(buttons[0]).toHaveTextContent('Extra small')
    expect(buttons[5]).toHaveTextContent('Extra extra large')
  })

  it('active viewport button has active class and aria-pressed', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const mdButton = screen.getByTestId('viewport-button-md')

    expect(mdButton).toHaveAttribute('aria-pressed', 'true')
  })

  it('clicking inactive button switches viewport', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const mdButton = screen.getByTestId('viewport-button-md')
    const lgButton = screen.getByTestId('viewport-button-lg')

    expect(lgButton).toHaveAttribute('aria-pressed', 'false')

    await user.click(lgButton)

    expect(lgButton).toHaveAttribute('aria-pressed', 'true')
    expect(mdButton).toHaveAttribute('aria-pressed', 'false')
  })

  it('active button click does not call setActiveViewport', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    // Spy on the shared store setter the component invokes via useViewportContext.
    // The store itself no-ops on an unchanged key, so aria-pressed alone can't
    // distinguish "setter skipped" (guard present) from "setter called with the
    // same key" (guard removed) — we must assert on the call directly.
    const setSpy = vi.spyOn(activeViewportStore, 'setActiveViewport')

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })
    // renderWithProviders seeds the active viewport through the same setter.
    setSpy.mockClear()

    const mdButton = screen.getByTestId('viewport-button-md')
    await user.click(mdButton)

    // Clicking the already-active button must NOT call the setter (the `!isActive`
    // guard). A mutant that always calls it would invoke setActiveViewport('md').
    expect(setSpy).not.toHaveBeenCalled()
    expect(mdButton).toHaveAttribute('aria-pressed', 'true')

    // Sanity: an inactive button DOES call the setter.
    await user.click(screen.getByTestId('viewport-button-lg'))
    expect(setSpy).toHaveBeenCalledWith('lg')

    setSpy.mockRestore()
  })

  it('reset button not shown when no overrides', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />)

    expect(screen.queryByTestId('reset-overrides-button')).not.toBeInTheDocument()
  })

  it('active button has aria-disabled attribute', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const mdButton = screen.getByTestId('viewport-button-md')

    expect(mdButton).toHaveAttribute('aria-disabled', 'true')
  })

  it('inactive buttons do not have aria-disabled attribute', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    expect(screen.getByTestId('viewport-button-xs')).not.toHaveAttribute('aria-disabled')
    expect(screen.getByTestId('viewport-button-lg')).not.toHaveAttribute('aria-disabled')
  })

  it('inactive buttons do not have active class', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const xsButton = screen.getByTestId('viewport-button-xs')

    expect(xsButton).toHaveAttribute('aria-pressed', 'false')
  })

  it('clicking active button does not change active state', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const mdButton = screen.getByTestId('viewport-button-md')

    await user.click(mdButton)

    expect(mdButton).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('viewport-button-xs')).toHaveAttribute('aria-pressed', 'false')
    expect(screen.getByTestId('viewport-button-lg')).toHaveAttribute('aria-pressed', 'false')
  })

  it('inactive buttons do not have aria-disabled attribute at all', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    for (const key of VIEWPORT_KEYS) {
      if (key === 'md') continue
      expect(screen.getByTestId(`viewport-button-${key}`)).not.toHaveAttribute('aria-disabled')
    }
  })

  it('shows reset button when overrides exist in cache', () => {
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('md'))

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md', queryClient })

    const resetButton = screen.getByTestId('reset-overrides-button')
    expect(resetButton).toBeInTheDocument()
    expect(resetButton).toHaveTextContent('Reset all')
  })

  it('shows the reset button by default (readonly omitted) when overrides exist', () => {
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('md'))

    renderWithProviders(<ViewportSwitcher readonly={false} />, { viewport: 'md', queryClient })

    expect(screen.getByTestId('reset-overrides-button')).toBeInTheDocument()
  })

  it('hides the reset button and dialog when readonly even with overrides present', () => {
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('md'))

    renderWithProviders(<ViewportSwitcher readonly />, { viewport: 'md', queryClient })

    expect(screen.queryByTestId('reset-overrides-button')).not.toBeInTheDocument()
    expect(screen.queryByTestId('confirm-dialog')).not.toBeInTheDocument()
  })

  it('shows "Reset viewport" label for non-default viewport with overrides', () => {
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg'))

    renderWithProviders(<ViewportSwitcher />, { viewport: 'lg', queryClient })

    const resetButton = screen.getByTestId('reset-overrides-button')
    expect(resetButton).toBeInTheDocument()
    expect(resetButton).toHaveTextContent('Reset viewport')
  })

  it('exposes an accessible toolbar name', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />)

    expect(screen.getByRole('toolbar', { name: 'Viewport size' })).toBeInTheDocument()
  })

  it('renders the upper-bound range label for a non-final viewport', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />)

    // The first viewport (xs) is followed by sm (minWidth 576), so its
    // range label reads the next viewport's lower bound.
    const xsButton = screen.getByTestId('viewport-button-xs')
    expect(xsButton).toHaveTextContent('<576')
  })

  it('omits the range label for the final viewport', () => {
    mockFetchSuccess({})

    renderWithProviders(<ViewportSwitcher />)

    // xxl is the last viewport — it has no upper bound, so no range text.
    const xxlButton = screen.getByTestId('viewport-button-xxl')
    expect(xxlButton).not.toHaveTextContent('<')
    expect(xxlButton).toHaveTextContent('Extra extra large')
    // The `range !== null` guard must omit the span element entirely — not
    // render an empty one — for the final viewport.
    expect(xxlButton.querySelector('.ssgrid-viewport-switcher__range')).toBeNull()
  })

  it('does not render the confirm dialog until reset is clicked', () => {
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg'))

    renderWithProviders(<ViewportSwitcher />, { viewport: 'lg', queryClient })

    expect(screen.queryByText('Reset Large overrides')).not.toBeInTheDocument()
    expect(screen.queryByTestId('confirm-dialog')).not.toBeInTheDocument()
  })

  it('opens the confirm dialog with title, message and confirm label on reset click', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false, gcTime: 0 } },
    })

    queryClient.setQueryData(queryKeys.elementTree.byPage(1, 'main'), treeWithOverride('lg'))

    renderWithProviders(<ViewportSwitcher />, { viewport: 'lg', queryClient })

    await user.click(screen.getByTestId('reset-overrides-button'))

    expect(screen.getByTestId('confirm-dialog')).toBeInTheDocument()
    expect(screen.getByText('Reset Large overrides')).toBeInTheDocument()
    expect(screen.getByText('Reset overrides for 1 column on Large?')).toBeInTheDocument()
    expect(screen.getByText('Reset')).toBeInTheDocument()
  })
})
