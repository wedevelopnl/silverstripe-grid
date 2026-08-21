import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import * as endpoints from '@/api/endpoints'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import AddChildButton from './AddChildButton'

describe('AddChildButton', () => {
  it('renders with correct label text', () => {
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    expect(screen.getByTestId('add-child-button')).toHaveTextContent('Add Row')
  })

  it('click triggers createElement mutation with the correct containerType and parent NodeRef', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'row',
      parent: { type: 'section', id: 10 },
    })
    expect(body.zone).toBeUndefined()
  })

  it('section type includes zone in mutation payload and uses a page parent', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={1} childType="section" variant="append" />, {
      zone: 'main',
    })

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'section',
      parent: { type: 'page', id: 1 },
      zone: 'main',
    })
  })

  it('shows "Adding..." text during mutation', async () => {
    const user = userEvent.setup()

    // Never-resolving fetch to keep the mutation pending
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(screen.getByTestId('add-child-button')).toHaveTextContent('Adding Row')
    })
  })

  it('marks the button aria-disabled during mutation without blurring it', async () => {
    const user = userEvent.setup()
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    const button = screen.getByTestId('add-child-button')
    // Idle first: `aria-disabled` is the only disabled signal now, so a stuck
    // `true` would announce the button as unavailable for its whole resting
    // life while every click-path test stayed green.
    expect(button).toHaveAttribute('aria-disabled', 'false')

    await user.click(button)

    await waitFor(() => {
      expect(button).toHaveAttribute('aria-disabled', 'true')
    })
    // A real `disabled` would have been blurred by the browser, stranding the
    // keyboard user and silencing the label change.
    expect(button).toHaveFocus()
    expect(button).toBeEnabled()
  })

  it('ignores a second click while the mutation is still pending', async () => {
    const user = userEvent.setup()
    const fetchSpy = vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    const button = screen.getByTestId('add-child-button')
    await user.click(button)
    await waitFor(() => {
      expect(button).toHaveAttribute('aria-disabled', 'true')
    })

    await user.click(button)

    // aria-disabled does not stop the click, so the handler's own guard is the
    // only thing preventing a duplicate element.
    expect(fetchSpy).toHaveBeenCalledTimes(1)
  })

  it('empty-state variant renders message', () => {
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="empty-state" />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No rows yet')).toBeInTheDocument()
  })

  it('append variant renders without message', () => {
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    expect(screen.getByTestId('add-child-append')).toBeInTheDocument()
    expect(screen.queryByText('No rows yet')).not.toBeInTheDocument()
  })

  it('between variant renders the between wrapper', () => {
    mockFetchSuccess({})

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" variant="between" insertAfterId={7} />,
    )

    expect(screen.getByTestId('add-child-between')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-append')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
  })

  it('before-first variant renders its own wrapper, distinct from the between one', () => {
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="before-first" />)

    expect(screen.getByTestId('add-child-before-first')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-between')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-child-append')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
  })

  it('before-first variant sends insertAtStart instead of insertAfterElementID', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(
      <AddChildButton parentId={1} childType="section" variant="before-first" />,
      {
        zone: 'main',
      },
    )

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'section',
      parent: { type: 'page', id: 1 },
      zone: 'main',
      insertAtStart: true,
    })
    expect(body.insertAfterElementID).toBeUndefined()
  })

  it('includes insertAfterElementID in the mutation payload when insertAfterId is given', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" variant="between" insertAfterId={7} />,
    )

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({
      containerType: 'row',
      parent: { type: 'section', id: 10 },
      insertAfterElementID: 7,
    })
  })

  it('omits the insertAfterElementID key entirely from the create params when insertAfterId is undefined', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    // JSON.stringify drops an `insertAfterElementID: undefined` field, so the
    // serialized fetch body cannot distinguish "key absent" from "key present
    // but undefined". Inspect the raw params object the endpoint receives, where
    // the conditional-spread guard's effect is observable via own-key presence.
    const createSpy = vi.spyOn(endpoints, 'createElement')

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(createSpy).toHaveBeenCalled()
    })

    // A mutant that always spreads `{ insertAfterElementID }` adds the key with
    // value undefined; the guard must leave it off entirely.
    expect(createSpy.mock.calls[0][0]).not.toHaveProperty('insertAfterElementID')

    createSpy.mockRestore()
  })
})

describe('AddChildButton shared route', () => {
  it('offers the caret on every gap variant, not just the trailing one', () => {
    mockFetchSuccess({})

    for (const variant of ['append', 'before-first', 'empty-state'] as const) {
      const { unmount } = renderWithProviders(
        <AddChildButton parentId={10} childType="row" variant={variant} />,
      )
      expect(screen.getByTestId('add-child-shared-trigger')).toBeInTheDocument()
      unmount()
    }

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" variant="between" insertAfterId={7} />,
    )
    expect(screen.getByTestId('add-child-shared-trigger')).toBeInTheDocument()
  })

  it('names the route after what it actually places', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(<AddChildButton parentId={1} childType="section" variant="append" />)

    await user.click(screen.getByTestId('add-child-shared-trigger'))

    expect(screen.getByRole('menuitem', { name: 'Place shared section…' })).toBeInTheDocument()
  })

  it('offers no shared route inside the library editor — a block may not hold a block', () => {
    mockFetchSuccess({})

    renderWithProviders(
      <AddChildButton
        parentId={9}
        childType="section"
        variant="empty-state"
        parentType="sharedBlock"
      />,
    )

    expect(screen.queryByTestId('add-child-shared-trigger')).not.toBeInTheDocument()
  })

  it('opens the picker filtered to the parent kind this host represents', async () => {
    const user = userEvent.setup()
    mockFetchSuccess([])

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="append" />)

    await user.click(screen.getByTestId('add-child-shared-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Place shared row…' }))

    expect(await screen.findByTestId('shared-block-picker')).toBeInTheDocument()

    const listCall = getFetchCalls().find(([url]) => String(url).includes('api/list?'))
    expect(listCall?.[0]).toBe('/admin/grid-shared-blocks/api/list?parentType=section')
  })

  it('carries the gap position into the placement — between sends insertAfterElementID', async () => {
    const user = userEvent.setup()
    mockFetchSuccess([
      { id: 3, title: 'Banner', rootType: 'row', usageCount: 1, status: 'published' },
    ])

    renderWithProviders(
      <AddChildButton parentId={10} childType="row" variant="between" insertAfterId={7} />,
    )

    await user.click(screen.getByTestId('add-child-shared-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Place shared row…' }))
    await user.click(await screen.findByTestId('shared-block-picker-row'))

    await waitFor(() => {
      const call = getFetchCalls().find(([url]) =>
        String(url).includes('/admin/grid-shared-blocks/api/place'),
      )
      expect(call).toBeDefined()
      expect(JSON.parse(call![1]!.body as string)).toMatchObject({
        blockId: 3,
        parent: { type: 'section', id: 10 },
        insertAfterElementID: 7,
      })
    })
  })

  it('carries the leading gap into the placement — before-first sends insertAtStart', async () => {
    const user = userEvent.setup()
    mockFetchSuccess([
      { id: 3, title: 'Banner', rootType: 'row', usageCount: 1, status: 'published' },
    ])

    renderWithProviders(<AddChildButton parentId={10} childType="row" variant="before-first" />)

    await user.click(screen.getByTestId('add-child-shared-trigger'))
    await user.click(screen.getByRole('menuitem', { name: 'Place shared row…' }))
    await user.click(await screen.findByTestId('shared-block-picker-row'))

    await waitFor(() => {
      const call = getFetchCalls().find(([url]) =>
        String(url).includes('/admin/grid-shared-blocks/api/place'),
      )
      expect(call).toBeDefined()
      const body = JSON.parse(call![1]!.body as string)
      expect(body).toMatchObject({ blockId: 3, insertAtStart: true })
      expect(body.insertAfterElementID).toBeUndefined()
    })
  })

  // Regression: the suppression used to key off `parentType`, which only the
  // root empty-state button receives. Every nested add strip inside the library
  // editor therefore still offered a placement the server rejects.
  it.each(['section', 'row', 'column'] as const)(
    'offers no shared route on a %s add strip inside the library editor',
    (childType) => {
      renderWithProviders(<AddChildButton parentId={7} childType={childType} variant="append" />, {
        rootType: 'sharedBlock',
      })

      expect(screen.queryByTestId('add-child-shared-trigger')).toBeNull()
    },
  )

  it('still offers the shared route on the same strip in a page zone', () => {
    renderWithProviders(<AddChildButton parentId={7} childType="row" variant="append" />)

    expect(screen.getByTestId('add-child-shared-trigger')).toBeInTheDocument()
  })

  // A block-rooted editor's zone is '' — a block has none — and the server
  // rejects an empty zone before the service, which clears the zone for a block
  // parent anyway, ever sees it. Sending it made seeding a block a 400.
  it('omits the zone when seeding a block, whose root section belongs to no zone', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    renderWithProviders(
      <AddChildButton
        parentId={9}
        childType="section"
        variant="empty-state"
        parentType="sharedBlock"
      />,
      { rootType: 'sharedBlock', zone: '' },
    )

    await user.click(screen.getByTestId('add-child-button'))

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
    })

    const [, init] = getFetchCalls()[0]
    const body = JSON.parse(init!.body as string)

    expect(body).toMatchObject({ containerType: 'section', parent: { type: 'sharedBlock', id: 9 } })
    expect(body.zone).toBeUndefined()
  })
})
