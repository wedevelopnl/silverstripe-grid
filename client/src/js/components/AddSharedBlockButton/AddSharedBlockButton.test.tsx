import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import GridQueryProvider from '@/hooks/QueryProvider'
import { getFetchCalls, mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import type { AllowedTypeInfo } from '@/types/elements'
import { showToast } from '@/utils/toast'

import AddSharedBlockButton from './AddSharedBlockButton'

vi.mock('@/utils/toast', () => ({
  showToast: vi.fn(),
}))

const LEAF_TYPES: Record<string, AllowedTypeInfo> = {
  'WeDevelop\\Grid\\Model\\ContentElement': {
    label: 'Content',
    icon: 'font-icon-block-content',
    description: 'A block of text',
  },
}

const CREATED = { id: 7, editLink: '/admin/shared-blocks/item/7/edit' }

let assign: ReturnType<typeof vi.fn>

function renderButton(leafTypes: Record<string, AllowedTypeInfo> = LEAF_TYPES) {
  render(
    <GridQueryProvider>
      <AddSharedBlockButton leafTypes={leafTypes} />
    </GridQueryProvider>,
  )
}

/** The body of the create call, or undefined when none was made. */
function createdWith(): unknown {
  const call = getFetchCalls().find(([url]) => String(url).includes('api/create'))

  return call === undefined ? undefined : JSON.parse(call[1]?.body as string)
}

describe('AddSharedBlockButton', () => {
  beforeEach(() => {
    assign = vi.fn()
    // jsdom's Location.assign is non-configurable, so it cannot be spied on —
    // the whole location object is stubbed for the duration of the test.
    vi.stubGlobal('location', { ...window.location, assign })
    vi.mocked(showToast).mockClear()
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('creates a section-rooted block from the primary action', async () => {
    const user = userEvent.setup()
    mockFetchSuccess(CREATED)

    renderButton()
    await user.click(screen.getByTestId('add-shared-block-add'))

    await waitFor(() => {
      expect(createdWith()).toEqual({ containerType: 'section' })
    })
  })

  it.each([
    ['Add new shared row', 'row'],
    ['Add new shared column', 'column'],
  ])('creates a %s from the caret', async (label, containerType) => {
    const user = userEvent.setup()
    mockFetchSuccess(CREATED)

    renderButton()
    await user.click(screen.getByTestId('add-shared-block-menu-trigger'))
    await user.click(screen.getByRole('menuitem', { name: label }))

    await waitFor(() => {
      expect(createdWith()).toEqual({ containerType })
    })
  })

  it('creates a leaf-rooted block from the element type picked in the dialog', async () => {
    const user = userEvent.setup()
    mockFetchSuccess(CREATED)

    renderButton()
    await user.click(screen.getByTestId('add-shared-block-menu-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Add new shared content element…' }))

    expect(await screen.findByTestId('element-type-picker')).toBeInTheDocument()

    await user.click(screen.getByTestId('element-type-tile'))

    await waitFor(() => {
      expect(createdWith()).toEqual({ className: 'WeDevelop\\Grid\\Model\\ContentElement' })
    })
  })

  it('offers the element route with no tiles rather than hiding it when no leaf types exist', async () => {
    const user = userEvent.setup()
    mockFetchSuccess(CREATED)

    renderButton({})
    await user.click(screen.getByTestId('add-shared-block-menu-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Add new shared content element…' }))

    expect(await screen.findByTestId('element-type-picker')).toBeInTheDocument()
    expect(screen.queryByTestId('element-type-tile')).toBeNull()
  })

  it('opens the new block in the editor, so the author never sees the listing again', async () => {
    const user = userEvent.setup()
    mockFetchSuccess(CREATED)

    renderButton()
    await user.click(screen.getByTestId('add-shared-block-add'))

    await waitFor(() => {
      expect(assign).toHaveBeenCalledExactlyOnceWith(CREATED.editLink)
    })
  })

  it('stays put and reports the failure when the block could not be created', async () => {
    const user = userEvent.setup()
    mockFetchError(403)

    renderButton()
    await user.click(screen.getByTestId('add-shared-block-add'))

    await waitFor(() => {
      expect(showToast).toHaveBeenCalledOnce()
    })
    expect(assign).not.toHaveBeenCalled()
  })

  it('reports progress and refuses a second block while the first is in flight', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderButton()
    const button = screen.getByTestId('add-shared-block-add')
    await user.click(button)

    await waitFor(() => {
      expect(button).toHaveTextContent('Adding block…')
    })

    // aria-disabled keeps the button focusable, so the click still lands — the
    // pending guard inside the handler is what stops the second create.
    await user.click(button)

    expect(getFetchCalls()).toHaveLength(1)
  })
})
