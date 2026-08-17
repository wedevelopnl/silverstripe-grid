import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import ColumnInsertButton from './ColumnInsertButton'

describe('ColumnInsertButton', () => {
  it('start placement creates a column with insertAtStart', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />)

    const button = screen.getByTestId('column-insert-start')
    expect(button).toHaveAccessibleName('Add a column at the start')

    await user.click(button)

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'column',
      parent: { type: 'row', id: 7 },
      insertAtStart: true,
    })
    expect(body.insertAfterElementID).toBeUndefined()
  })

  it('end placement creates a column directly after the given column', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ColumnInsertButton rowId={7} placement="end" afterColumnId={42} />)

    const button = screen.getByTestId('column-insert-end')
    expect(button).toHaveAccessibleName('Add a column at the end')

    await user.click(button)

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'column',
      parent: { type: 'row', id: 7 },
      insertAfterElementID: 42,
    })
    expect(body.insertAtStart).toBeUndefined()
  })

  it('between placement creates a column directly after the column to its left', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<ColumnInsertButton rowId={7} placement="between" afterColumnId={9} />)

    const button = screen.getByTestId('column-insert-between')
    expect(button).toHaveAccessibleName('Add a column here')

    await user.click(button)

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'column',
      parent: { type: 'row', id: 7 },
      insertAfterElementID: 9,
    })
  })

  it('between placement carries the gutter-shift CSS var when given gutterShiftPct', () => {
    mockFetchSuccess({})

    renderWithProviders(
      <ColumnInsertButton rowId={1} placement="between" afterColumnId={2} gutterShiftPct={25} />,
    )

    expect(
      screen.getByTestId('column-insert-between').style.getPropertyValue('--ssgrid-insert-shift'),
    ).toBe('25%')
  })

  it('between placement omits the gutter-shift CSS var when gutterShiftPct is 0 or absent', () => {
    mockFetchSuccess({})

    renderWithProviders(<ColumnInsertButton rowId={1} placement="between" afterColumnId={2} />)

    expect(
      screen.getByTestId('column-insert-between').style.getPropertyValue('--ssgrid-insert-shift'),
    ).toBe('')
  })

  it('marks itself aria-disabled while the mutation is pending, keeping focus', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />)

    const button = screen.getByTestId('column-insert-start')
    // Idle first: `aria-disabled` is the only disabled signal now, so a stuck
    // `true` would announce the button as unavailable for its whole resting
    // life while every click-path test stayed green.
    expect(button).toHaveAttribute('aria-disabled', 'false')

    await user.click(button)

    await waitFor(() => {
      expect(button).toHaveAttribute('aria-disabled', 'true')
    })
    expect(button).toHaveFocus()
    expect(button).toBeEnabled()
  })

  it('ignores a second click while the mutation is still pending', async () => {
    const user = userEvent.setup()
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />)

    const button = screen.getByTestId('column-insert-start')
    await user.click(button)
    await waitFor(() => {
      expect(button).toHaveAttribute('aria-disabled', 'true')
    })

    await user.click(button)

    expect(fetchSpy).toHaveBeenCalledTimes(1)
  })
})
