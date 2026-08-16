import { QueryClient } from '@tanstack/react-query'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { queryKeys } from '@/hooks/queryKeys'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createTreeApiResponse,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import type { ColumnNode, ViewportSettings } from '@/types/elements'
import ViewportResetMenu from './ViewportResetMenu'

const OVERRIDE: ViewportSettings = { width: 6, offset: 0, visible: true }
const DEFAULTS: ViewportSettings = { width: 12, offset: 0, visible: true }

/** One column per entry, each overriding exactly the viewports named for it. */
function renderMenu(columnViewports: string[][], viewport = 'md') {
  const columns: ColumnNode[] = columnViewports.map((viewports) =>
    createColumnNode({
      gridSettings: {
        default: DEFAULTS,
        overrides: Object.fromEntries(viewports.map((key) => [key, OVERRIDE])),
      },
      children: [],
    }),
  )

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  })
  queryClient.setQueryData(
    queryKeys.elementTree.byPage(1, 'main'),
    createTreeApiResponse({
      pageId: 1,
      sections: [
        createSectionNode({
          parent: { type: 'page', id: 1 },
          children: [createRowNode({ children: columns })],
        }),
      ],
    }),
  )

  return renderWithProviders(<ViewportResetMenu />, { viewport, queryClient })
}

function menuItems() {
  return within(screen.getByTestId('viewport-reset-dropdown')).getAllByRole('menuitem')
}

describe('ViewportResetMenu', () => {
  beforeEach(() => {
    // jsdom does not implement HTMLDialogElement.showModal/close
    HTMLDialogElement.prototype.showModal = vi.fn()
    HTMLDialogElement.prototype.close = vi.fn()
  })

  it('renders nothing when the page carries no overrides', () => {
    mockFetchSuccess({})

    renderMenu([])

    expect(screen.queryByTestId('viewport-reset-trigger')).not.toBeInTheDocument()
  })

  it('names every scope with the number of columns it would clear', async () => {
    // The control this replaced revealed the reach only inside the dialog.
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))

    expect(menuItems().map((item) => item.textContent)).toEqual([
      'Extra small2',
      'Large1',
      'All viewports3',
    ])
  })

  it('gives each item an accessible name that reads as a sentence', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))

    // Visually the row is a name and a bare number in two columns; unlabelled
    // it would announce as "Extra small 2".
    expect(menuItems().map((item) => item.getAttribute('aria-label'))).toEqual([
      'Reset Extra small, 2 columns',
      'Reset Large, 1 column',
      'Reset All viewports, 3 columns',
    ])
  })

  it('confirms before resetting, naming the chosen scope', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Reset Large, 1 column' }))

    expect(screen.getByTestId('confirm-dialog')).toBeInTheDocument()
    expect(screen.getByText('Reset Large overrides')).toBeInTheDocument()
    expect(screen.getByText('Reset overrides for 1 column on Large?')).toBeInTheDocument()
  })

  it('closes the menu when a scope is chosen', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Reset Large, 1 column' }))

    expect(screen.queryByTestId('viewport-reset-dropdown')).not.toBeInTheDocument()
  })

  it('sends the chosen viewport, not the active one, on confirm', async () => {
    // The whole point of the reshape: the active tab must no longer decide
    // what gets cleared.
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']], 'xs')
    await user.click(screen.getByTestId('viewport-reset-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Reset Large, 1 column' }))
    await user.click(screen.getByText('Reset'))

    const call = getFetchCalls().find(([url]) =>
      (url as string).includes('/api/resetGridSettingsOverrides'),
    )
    expect(call).toBeDefined()
    expect(String(call?.[0])).toContain('viewport=lg')
  })

  it('sends no viewport for the all-viewports scope', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']], 'xs')
    await user.click(screen.getByTestId('viewport-reset-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Reset All viewports, 2 columns' }))
    await user.click(screen.getByText('Reset'))

    const call = getFetchCalls().find(([url]) =>
      (url as string).includes('/api/resetGridSettingsOverrides'),
    )
    expect(call).toBeDefined()
    expect(String(call?.[0])).not.toContain('viewport=')
  })

  it('offers a single scope, without an "all" duplicate, when one viewport deviates', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['lg'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))

    expect(menuItems().map((item) => item.textContent)).toEqual(['Large2'])
  })

  it('activates the keyboard-focused scope on Enter', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))
    await user.keyboard('{ArrowDown}{Enter}')

    expect(screen.getByText('Reset Large overrides')).toBeInTheDocument()
  })

  it('marks the aggregate scope so it can be set apart from the entries it covers', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderMenu([['xs'], ['lg']])
    await user.click(screen.getByTestId('viewport-reset-trigger'))

    expect(menuItems().map((item) => item.getAttribute('data-scope'))).toEqual(['xs', 'lg', 'all'])
  })
})
