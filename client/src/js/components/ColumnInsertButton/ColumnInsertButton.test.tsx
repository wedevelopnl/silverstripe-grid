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

    const button = screen.getByTestId('column-insert-start-add')
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

    const button = screen.getByTestId('column-insert-end-add')
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

    const button = screen.getByTestId('column-insert-between-add')
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

    const button = screen.getByTestId('column-insert-start-add')
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

    const button = screen.getByTestId('column-insert-start-add')
    await user.click(button)
    await waitFor(() => {
      expect(button).toHaveAttribute('aria-disabled', 'true')
    })

    await user.click(button)

    expect(fetchSpy).toHaveBeenCalledTimes(1)
  })

  describe('shared route', () => {
    it('offers the shared route at every placement, named for what it places', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />)

      await user.click(screen.getByTestId('column-insert-shared-trigger'))

      expect(screen.getByRole('menuitem', { name: 'Place shared column…' })).toBeInTheDocument()
    })

    it('start placement places a shared column before every sibling', async () => {
      const user = userEvent.setup()
      mockFetchSuccess([
        { id: 3, title: 'Card', rootType: 'column', usageCount: 1, status: 'published' },
      ])

      renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />)

      await user.click(screen.getByTestId('column-insert-shared-trigger'))
      await user.click(screen.getByRole('menuitem', { name: 'Place shared column…' }))
      await user.click(await screen.findByTestId('shared-block-picker-row'))

      await waitFor(() => {
        const call = getFetchCalls().find(([url]) =>
          String(url).includes('/admin/grid-shared-blocks/api/place'),
        )
        expect(call).toBeDefined()
        const body = JSON.parse(call![1]!.body as string)
        expect(body).toMatchObject({
          blockId: 3,
          parent: { type: 'row', id: 7 },
          insertAtStart: true,
        })
        expect(body.insertAfterElementID).toBeUndefined()
      })
    })

    it('between placement places a shared column after the column to its left', async () => {
      const user = userEvent.setup()
      mockFetchSuccess([
        { id: 3, title: 'Card', rootType: 'column', usageCount: 1, status: 'published' },
      ])

      renderWithProviders(<ColumnInsertButton rowId={7} placement="between" afterColumnId={9} />)

      await user.click(screen.getByTestId('column-insert-shared-trigger'))
      await user.click(screen.getByRole('menuitem', { name: 'Place shared column…' }))
      await user.click(await screen.findByTestId('shared-block-picker-row'))

      await waitFor(() => {
        const call = getFetchCalls().find(([url]) =>
          String(url).includes('/admin/grid-shared-blocks/api/place'),
        )
        expect(call).toBeDefined()
        expect(JSON.parse(call![1]!.body as string)).toMatchObject({
          blockId: 3,
          parent: { type: 'row', id: 7 },
          insertAfterElementID: 9,
        })
      })
    })

    it('filters the library to column-rooted blocks', async () => {
      const user = userEvent.setup()
      mockFetchSuccess([])

      renderWithProviders(<ColumnInsertButton rowId={7} placement="end" afterColumnId={42} />)

      await user.click(screen.getByTestId('column-insert-shared-trigger'))
      await user.click(screen.getByRole('menuitem', { name: 'Place shared column…' }))

      expect(await screen.findByTestId('shared-block-picker')).toBeInTheDocument()

      const listCall = getFetchCalls().find(([url]) => String(url).includes('api/list?'))
      expect(listCall?.[0]).toBe('/admin/grid-shared-blocks/api/list?parentType=row')
    })
  })

  it('offers no shared column route inside the library editor', () => {
    // The caret had no suppression signal at all before: a block may not
    // contain a block, at any depth.
    renderWithProviders(<ColumnInsertButton rowId={7} placement="start" />, {
      root: { kind: 'sharedBlock', blockId: 1 },
    })

    expect(screen.queryByTestId('column-insert-shared-trigger')).toBeNull()
  })

  // `data-split` is what the stylesheet sizes the pill from: with the caret
  // suppressed the control is the bare "+" square, and without the flag it kept
  // the split's 44px width and squared trailing corners — a half-empty pill.
  it('flags itself as split only while the caret is rendered', () => {
    const { unmount } = renderWithProviders(
      <ColumnInsertButton rowId={7} placement="between" afterColumnId={9} />,
    )

    expect(screen.getByTestId('column-insert-between')).toHaveAttribute('data-split', '')

    unmount()

    renderWithProviders(<ColumnInsertButton rowId={7} placement="between" afterColumnId={9} />, {
      root: { kind: 'sharedBlock', blockId: 1 },
    })

    expect(screen.getByTestId('column-insert-between')).not.toHaveAttribute('data-split')
  })
})
