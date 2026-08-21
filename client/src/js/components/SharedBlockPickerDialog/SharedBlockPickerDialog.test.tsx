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

    const [first] = await screen.findAllByTestId('shared-block-picker-row')
    expect(first).toHaveTextContent('section')
    expect(first).toHaveTextContent('2')
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
})
