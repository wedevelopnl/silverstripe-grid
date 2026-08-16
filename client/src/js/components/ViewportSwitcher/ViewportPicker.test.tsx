import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { ResetScopeOption } from '@/hooks/useResetOverridesAction'
import type { ViewportConfig } from '@/types/adapter'
import ViewportPicker from './ViewportPicker'

const VIEWPORTS = [
  { key: 'xs', label: 'Extra small', minWidth: 0 },
  { key: 'md', label: 'Medium', minWidth: 768 },
  { key: 'lg', label: 'Large', minWidth: 992 },
] as unknown as ViewportConfig[]

const RESET_OPTIONS: ResetScopeOption[] = [
  { viewport: 'xs', label: 'Extra small', count: 2, actionLabel: 'Reset Extra small, 2 columns' },
  {
    viewport: null,
    label: 'All viewports',
    count: 2,
    actionLabel: 'Reset All viewports, 2 columns',
  },
]

function renderPicker(overrides: Partial<React.ComponentProps<typeof ViewportPicker>> = {}) {
  const props = {
    viewports: VIEWPORTS,
    activeViewport: 'md',
    defaultViewport: 'md',
    onSelectViewport: vi.fn(),
    overrideCounts: { xs: 2 },
    resetOptions: RESET_OPTIONS,
    onSelectReset: vi.fn(),
    ...overrides,
  }
  render(<ViewportPicker {...props} />)
  return props
}

const dropdown = () => screen.getByTestId('viewport-picker-dropdown')

describe('ViewportPicker', () => {
  it('shows the current viewport on the trigger', () => {
    renderPicker()

    const trigger = screen.getByTestId('viewport-picker-trigger')
    expect(trigger).toHaveTextContent('Medium')
    expect(trigger).toHaveAccessibleName('Viewport: Medium')
  })

  it('marks the trigger when anything on the page is overridden', () => {
    renderPicker()

    expect(
      screen
        .getByTestId('viewport-picker-trigger')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).not.toBeNull()
  })

  it('leaves the trigger unmarked when nothing is overridden', () => {
    renderPicker({ overrideCounts: {}, resetOptions: [] })

    expect(
      screen
        .getByTestId('viewport-picker-trigger')
        .querySelector('.ssgrid-viewport-picker__override-dot'),
    ).toBeNull()
  })

  it('marks which viewport is the adapter default', async () => {
    // Editing at the default changes the layout everywhere; editing anywhere
    // else records an override against it, so the two are not interchangeable.
    const user = userEvent.setup()
    renderPicker({ activeViewport: 'lg', defaultViewport: 'md' })

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    const marked = within(dropdown())
      .getAllByRole('menuitemradio')
      .filter((row) => row.querySelector('.ssgrid-viewport-picker__default') !== null)
    expect(marked).toHaveLength(1)
    expect(marked[0]).toHaveAttribute('data-testid', 'viewport-picker-option-md')
  })

  it('marks the trigger when the default viewport is the one selected', () => {
    renderPicker({ activeViewport: 'md', defaultViewport: 'md' })

    expect(
      screen
        .getByTestId('viewport-picker-trigger')
        .querySelector('.ssgrid-viewport-picker__default'),
    ).not.toBeNull()
  })

  it('leaves the trigger unmarked while a non-default viewport is selected', () => {
    // The marker says "you are editing the base layout" — it must not linger
    // once the author has moved to a viewport where edits become overrides.
    renderPicker({ activeViewport: 'lg', defaultViewport: 'md' })

    expect(
      screen
        .getByTestId('viewport-picker-trigger')
        .querySelector('.ssgrid-viewport-picker__default'),
    ).toBeNull()
  })

  it('offers every viewport as a radio, with the active one checked', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    const radios = within(dropdown()).getAllByRole('menuitemradio')
    expect(
      radios.map((r) => r.querySelector('.ssgrid-viewport-picker__item-label')?.textContent),
    ).toEqual(['Extra small', 'Medium', 'Large'])
    expect(radios.map((r) => r.getAttribute('aria-checked'))).toEqual(['false', 'true', 'false'])
  })

  it('announces the override count on a viewport row that has one', async () => {
    // The dot is decorative; the count reaches assistive tech as text.
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    const rows = within(dropdown()).getAllByRole('menuitemradio')
    expect(rows[0]).toHaveTextContent('2 columns override this viewport')
    expect(rows[1]).not.toHaveTextContent('override this viewport')
  })

  it('carries the reset scopes in the same menu, as plain items not radios', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    // Separate roles keep "switch to this" distinct from "clear this".
    expect(
      within(dropdown())
        .getAllByRole('menuitem')
        .map((i) => i.getAttribute('aria-label')),
    ).toEqual(['Reset Extra small, 2 columns', 'Reset All viewports, 2 columns'])
  })

  it('omits the reset group entirely in readonly mode', async () => {
    const user = userEvent.setup()
    renderPicker({ resetOptions: [] })

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    expect(within(dropdown()).queryAllByRole('menuitem')).toHaveLength(0)
    expect(within(dropdown()).getAllByRole('menuitemradio')).toHaveLength(3)
  })

  it('switches viewport and closes when a viewport row is chosen', async () => {
    const user = userEvent.setup()
    const props = renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))
    await user.click(screen.getByTestId('viewport-picker-option-lg'))

    expect(props.onSelectViewport).toHaveBeenCalledWith('lg')
    expect(screen.queryByTestId('viewport-picker-dropdown')).not.toBeInTheDocument()
  })

  it('does not re-select the viewport already active', async () => {
    const user = userEvent.setup()
    const props = renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))
    await user.click(screen.getByTestId('viewport-picker-option-md'))

    expect(props.onSelectViewport).not.toHaveBeenCalled()
  })

  it('requests a reset without touching the viewport when a reset row is chosen', async () => {
    const user = userEvent.setup()
    const props = renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Reset All viewports, 2 columns' }))

    expect(props.onSelectReset).toHaveBeenCalledWith(RESET_OPTIONS[1])
    expect(props.onSelectViewport).not.toHaveBeenCalled()
  })

  it('opens on the active viewport rather than the first row', async () => {
    const user = userEvent.setup()
    renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))

    // Enter without arrowing must land on 'md', the active one.
    await user.keyboard('{Enter}')
    expect(screen.queryByTestId('viewport-picker-dropdown')).not.toBeInTheDocument()
  })

  it('walks from the viewport rows into the reset rows with one keyboard order', async () => {
    const user = userEvent.setup()
    const props = renderPicker()

    await user.click(screen.getByTestId('viewport-picker-trigger'))
    // Seeded on 'md' (index 1): three downs reach the first reset row (index 3).
    await user.keyboard('{ArrowDown}{ArrowDown}{Enter}')

    expect(props.onSelectReset).toHaveBeenCalledWith(RESET_OPTIONS[0])
  })
})
