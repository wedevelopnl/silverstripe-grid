import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { resetIdCounter } from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import SharedBlockPickerDialog from './SharedBlockPickerDialog'

const BLOCKS = [
  { id: 3, title: 'Hero banner', rootType: 'section', usageCount: 2, status: 'published' },
  { id: 4, title: 'Newsletter form', rootType: 'section', usageCount: 0, status: 'notPublished' },
]

beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess(BLOCKS)

  // jsdom implements neither showModal nor close on <dialog>.
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
})

function renderPicker(
  overrides: Partial<React.ComponentProps<typeof SharedBlockPickerDialog>> = {},
) {
  return renderWithProviders(
    <SharedBlockPickerDialog
      parentType="page"
      parent={{ type: 'page', id: 1 }}
      zone="main"
      isOpen
      onClose={vi.fn()}
      {...overrides}
    />,
  )
}

describe('SharedBlockPickerDialog', () => {
  it('lists the blocks the server offered for this parent', async () => {
    renderPicker()

    const rows = await screen.findAllByTestId('shared-block-picker-row')
    expect(rows.map((row) => row.textContent)).toEqual([
      expect.stringContaining('Hero banner'),
      expect.stringContaining('Newsletter form'),
    ])
  })

  it('requests only the blocks that fit the target parent', async () => {
    renderPicker({ parentType: 'column', parent: { type: 'column', id: 9 } })

    await screen.findAllByTestId('shared-block-picker-row')

    expect(getFetchCalls()[0][0]).toBe('/admin/grid-shared-blocks/api/list?parentType=column')
  })

  it('shows each block root type and page count', async () => {
    renderPicker()

    // The root type is shown as a name, never the wire enum.
    const [first] = await screen.findAllByTestId('shared-block-picker-row')
    expect(first).toHaveTextContent('Section')
    expect(first).toHaveTextContent('Used on 2 pages')
  })

  it('filters the list by title as the author types', async () => {
    renderPicker()
    await screen.findAllByTestId('shared-block-picker-row')

    await userEvent.type(screen.getByTestId('shared-block-picker-search'), 'newsletter')

    const rows = screen.getAllByTestId('shared-block-picker-row')
    expect(rows).toHaveLength(1)
    expect(rows[0]).toHaveTextContent('Newsletter form')
  })

  it('reports when the search matches nothing', async () => {
    renderPicker()
    await screen.findAllByTestId('shared-block-picker-row')

    await userEvent.type(screen.getByTestId('shared-block-picker-search'), 'nothing matches')

    expect(screen.queryAllByTestId('shared-block-picker-row')).toHaveLength(0)
    expect(screen.getByText(/No shared block fits here yet/)).toBeInTheDocument()
  })

  it('places the chosen block with the exact placement parameters and closes', async () => {
    const onClose = vi.fn()
    renderPicker({ insertAfterElementID: 12, onClose })

    const rows = await screen.findAllByTestId('shared-block-picker-row')
    await userEvent.click(rows[0])

    const placeCall = getFetchCalls().find(([url]) =>
      String(url).includes('/admin/grid-shared-blocks/api/place'),
    )
    expect(placeCall).toBeDefined()
    expect(JSON.parse(String(placeCall?.[1]?.body))).toEqual({
      blockId: 3,
      parent: { type: 'page', id: 1 },
      zone: 'main',
      insertAfterElementID: 12,
    })
    expect(onClose).toHaveBeenCalled()
  })

  it('reports an empty library rather than rendering a bare list', async () => {
    mockFetchSuccess([])
    renderPicker()

    expect(await screen.findByText(/No shared block fits here yet/)).toBeInTheDocument()
  })

  it('fetches nothing while closed', () => {
    renderPicker({ isOpen: false })

    expect(getFetchCalls()).toHaveLength(0)
  })

  describe('keyboard', () => {
    it('names the dialog by its heading', async () => {
      renderPicker()

      await screen.findAllByTestId('shared-block-picker-row')

      expect(screen.getByRole('dialog', { name: 'Place a shared block' })).toBeInTheDocument()
    })

    it('walks the list with the arrow keys without moving DOM focus off it', async () => {
      renderPicker()

      const rows = await screen.findAllByTestId('shared-block-picker-row')
      const list = screen.getByRole('listbox')
      list.focus()

      expect(rows[0]).toHaveAttribute('data-active', 'true')

      await userEvent.keyboard('{ArrowDown}')

      expect(rows[1]).toHaveAttribute('data-active', 'true')
      expect(rows[0]).not.toHaveAttribute('data-active')
      // aria-activedescendant moves the highlight; focus stays on the listbox,
      // which is the whole point of the pattern.
      expect(list).toHaveFocus()
      expect(list).toHaveAttribute('aria-activedescendant', rows[1].id)
    })

    it('places the active block on Enter', async () => {
      const onClose = vi.fn()
      renderPicker({ onClose })

      await screen.findAllByTestId('shared-block-picker-row')
      screen.getByRole('listbox').focus()

      await userEvent.keyboard('{ArrowDown}{Enter}')

      const placeCall = getFetchCalls().find(([url]) =>
        String(url).includes('/admin/grid-shared-blocks/api/place'),
      )
      expect(JSON.parse(String(placeCall?.[1]?.body)).blockId).toBe(4)
      expect(onClose).toHaveBeenCalled()
    })
  })

  describe('row', () => {
    it('names the root type, which is what decides where the block may go', async () => {
      renderPicker()

      const rows = await screen.findAllByTestId('shared-block-picker-row')
      expect(rows[0]).toHaveTextContent('Section')
    })

    it('counts usage in whole pages, singular and plural', async () => {
      mockFetchSuccess([
        { id: 3, title: 'One', rootType: 'row', usageCount: 1, status: 'published' },
        { id: 4, title: 'Many', rootType: 'row', usageCount: 4, status: 'published' },
        { id: 5, title: 'None', rootType: 'row', usageCount: 0, status: 'published' },
      ])
      renderPicker()

      const rows = await screen.findAllByTestId('shared-block-picker-row')
      expect(rows[0]).toHaveTextContent('Used on 1 page')
      expect(rows[1]).toHaveTextContent('Used on 4 pages')
      expect(rows[2]).toHaveTextContent('Used on 0 pages')
    })

    it('warns before placing a block that would render nothing on live', async () => {
      renderPicker()

      const rows = await screen.findAllByTestId('shared-block-picker-row')

      // 'Newsletter form' has never been published.
      expect(rows[1]).toHaveTextContent('Not published yet')
      // 'Hero banner' is in sync, so it carries no mark at all.
      expect(rows[0].querySelector('[data-testid="shared-block-picker-status"]')).toBeNull()
    })

    it('marks a block whose draft has moved ahead of live', async () => {
      mockFetchSuccess([
        { id: 3, title: 'Edited', rootType: 'section', usageCount: 2, status: 'modified' },
      ])
      renderPicker()

      const [row] = await screen.findAllByTestId('shared-block-picker-row')
      expect(row).toHaveTextContent('Unpublished changes')
    })
  })
})
