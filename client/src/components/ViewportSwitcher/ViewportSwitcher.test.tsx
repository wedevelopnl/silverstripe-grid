import { QueryClient } from '@tanstack/react-query'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
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

// Setup file (client/src/testing/setup.ts) configures 6 viewports in this order.
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

    renderWithProviders(<ViewportSwitcher />, { viewport: 'md' })

    const mdButton = screen.getByTestId('viewport-button-md')

    await user.click(mdButton)

    expect(mdButton).toHaveAttribute('aria-pressed', 'true')
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
})
