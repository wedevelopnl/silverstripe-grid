import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createSimpleElement, createSharedBlockReferenceNode } from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import type { SharedBlockReferenceNode } from '@/types/elements'

import SharedPlacementActions from './SharedPlacementActions'

// jsdom doesn't implement native <dialog> showModal/close — stub them so the
// remove confirmation can actually open under test.
beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
})

const originalLocation = window.location

afterEach(() => {
  // Restore the real jsdom location if a test swapped it out.
  if (window.location !== originalLocation) {
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: originalLocation,
    })
  }
})

/**
 * jsdom's `window.location.assign` is non-configurable, so it can't be spied
 * directly. Replace the whole `location` with a stub exposing a mock `assign`
 * (`afterEach` restores the original). Returns the mock for assertions.
 */
function stubLocationAssign(): ReturnType<typeof vi.fn> {
  const assign = vi.fn()
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: { ...originalLocation, assign },
  })
  return assign
}

const BLOCK_LINK = '/admin/shared-blocks/item/5/edit'

function createPlacement(overrides?: {
  canDelete?: boolean
  blockEditLink?: string | null
}): SharedBlockReferenceNode {
  return createSharedBlockReferenceNode({
    id: 20,
    // The block's root element, whose own edit link must never be what the
    // actions follow — they lead to the block, not to the element rooting it.
    root: createSimpleElement({ id: 21, title: 'Shared Text' }),
    canDelete: overrides?.canDelete ?? true,
    sharedBlock: {
      blockId: 5,
      title: 'Shared Banner',
      usageCount: 2,
      status: 'published',
      editLink: overrides?.blockEditLink === undefined ? BLOCK_LINK : overrides.blockEditLink,
    },
  })
}

function renderActions(placement: SharedBlockReferenceNode) {
  return renderWithProviders(<SharedPlacementActions placement={placement} />)
}

describe('SharedPlacementActions', () => {
  it('renders exactly the open, edit and remove actions', () => {
    mockFetchSuccess({})

    renderActions(createPlacement())

    const toolbar = screen.getByTestId('shared-placement-toolbar')
    expect(toolbar.querySelectorAll('button')).toHaveLength(3)
    expect(screen.getByTestId('element-action-open')).toBeEnabled()
    expect(screen.getByTestId('element-action-edit')).toBeEnabled()
    expect(screen.getByTestId('element-action-remove')).toBeEnabled()
  })

  it('is a single tab stop the arrow keys walk', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderActions(createPlacement())

    const open = screen.getByTestId('element-action-open')
    const edit = screen.getByTestId('element-action-edit')
    const remove = screen.getByTestId('element-action-remove')

    // Only the first control is reachable by Tab; the rest are -1 until the
    // arrow keys move the stop.
    expect(open).toHaveProperty('tabIndex', 0)
    expect(edit).toHaveProperty('tabIndex', -1)
    expect(remove).toHaveProperty('tabIndex', -1)

    open.focus()
    await user.keyboard('{ArrowRight}')

    expect(edit).toHaveFocus()
    expect(edit).toHaveProperty('tabIndex', 0)
    expect(open).toHaveProperty('tabIndex', -1)

    await user.keyboard('{End}')
    expect(remove).toHaveFocus()

    await user.keyboard('{Home}')
    expect(open).toHaveFocus()
  })

  it('skips a disabled control when walking, and starts on the first enabled one', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    // No edit link disables both open and edit, leaving remove the only stop.
    renderActions(createPlacement({ blockEditLink: null }))

    const remove = screen.getByTestId('element-action-remove')
    expect(remove).toHaveProperty('tabIndex', 0)

    remove.focus()
    await user.keyboard('{ArrowLeft}')

    // A disabled button cannot hold focus, so the walk has nowhere to go.
    expect(remove).toHaveFocus()
  })

  it('opens the BLOCK in a new tab, not the element rooting it', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const open = vi.spyOn(window, 'open').mockImplementation(() => null)
    const placement = createPlacement()

    renderActions(placement)
    await user.click(screen.getByTestId('element-action-open'))

    expect(open).toHaveBeenCalledWith(BLOCK_LINK, '_blank', 'noopener,noreferrer')
    expect(open).not.toHaveBeenCalledWith(
      placement.children?.[0]?.editLink,
      expect.anything(),
      expect.anything(),
    )
  })

  it('navigates to the BLOCK from the edit action', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const assign = stubLocationAssign()

    renderActions(createPlacement())
    await user.click(screen.getByTestId('element-action-edit'))

    expect(assign).toHaveBeenCalledWith(BLOCK_LINK)
  })

  it('disables open and edit when the block has no edit link', () => {
    mockFetchSuccess({})

    renderActions(createPlacement({ blockEditLink: null }))

    expect(screen.getByTestId('element-action-open')).toBeDisabled()
    expect(screen.getByTestId('element-action-edit')).toBeDisabled()
  })

  it('removes the PLACEMENT, not the block content, after naming the block in the confirm', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})
    const placement = createPlacement()

    renderActions(placement)
    await user.click(screen.getByTestId('element-action-remove'))

    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveTextContent('Shared Banner')
    expect(dialog).toHaveTextContent('stays in the library')

    await user.click(screen.getByRole('button', { name: 'Remove' }))

    await waitFor(() => {
      expect(getFetchCalls().length).toBeGreaterThan(0)
    })
    const [url, init] = getFetchCalls()[0]
    expect(init?.method).toBe('DELETE')
    expect(String(url)).toContain('/api/delete')
    // The placement's id (20), never the root element's (21).
    expect(String(url)).toContain(`id=${placement.self.id}`)
  })

  it('disables the remove action when the placement cannot be deleted', () => {
    mockFetchSuccess({})

    renderActions(createPlacement({ canDelete: false }))

    expect(screen.getByTestId('element-action-remove')).toBeDisabled()
  })
})
