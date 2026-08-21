import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createSectionNode,
  createSharedBlockReferenceNode,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createTestQueryClient, renderWithProviders } from '@/testing/renderWithProviders'
import { queryKeys } from '@/hooks/queryKeys'
import type { ElementNode, SharedBlockReferenceNode } from '@/types/elements'
import SharedBlockFrame from './SharedBlockFrame'

// jsdom doesn't implement native <dialog> showModal/close — stub them so the
// publish/detach confirmations can actually open under test.
beforeEach(() => {
  resetIdCounter()
  mockFetchSuccess({})

  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
})

function renderFrame(node: SharedBlockReferenceNode, siblings: ElementNode[] = [node]) {
  return renderWithProviders(
    <SharedBlockFrame node={node} siblings={siblings}>
      <p>block content</p>
    </SharedBlockFrame>,
  )
}

/** Mutation calls only — the frame also reads the tree query on mount. */
function mutationCalls(path: string) {
  return getFetchCalls().filter(([url]) => String(url).includes(path))
}

/**
 * Render with the page tree already in cache. The move actions bail out while
 * `useElementTree` has no data — they need it as the optimistic-rollback
 * snapshot — so a reorder assertion only means anything once it is seeded.
 */
function renderFrameWithTree(node: SharedBlockReferenceNode, siblings: ElementNode[]) {
  const queryClient = createTestQueryClient()
  queryClient.setQueryData(
    queryKeys.elementTree.byPage(1, 'main'),
    createTreeApiResponse({ rootParent: { type: 'page', id: 1 }, nodes: siblings }),
  )

  return renderWithProviders(
    <SharedBlockFrame node={node} siblings={siblings}>
      <p>block content</p>
    </SharedBlockFrame>,
    { queryClient },
  )
}

/** Open the frame's overflow menu and return the visible item labels. */
async function openMenu() {
  await userEvent.click(screen.getByTestId('actions-menu-trigger'))
  return screen.getAllByRole('menuitem').map((item) => item.textContent)
}

describe('SharedBlockFrame', () => {
  it('renders the chip with the block title and its page count', () => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 3,
          title: 'Banner',
          usageCount: 4,
          status: 'published',
          editLink: '/admin/shared-blocks/item/3/edit',
        },
      }),
    )

    expect(screen.getByTestId('shared-block-chip')).toHaveTextContent('Banner')
    expect(screen.getByTestId('shared-block-chip')).toHaveTextContent('4')
  })

  it('renders its children inside the frame', () => {
    renderFrame(createSharedBlockReferenceNode())

    expect(screen.getByTestId('shared-block-frame')).toHaveTextContent('block content')
  })

  it.each([
    ['notPublished', 'Not published yet'],
    ['modified', 'Unpublished changes'],
  ] as const)('marks a %s block with its own label', (status, label) => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 3,
          title: 'Banner',
          usageCount: 1,
          status,
          editLink: '/admin/shared-blocks/item/3/edit',
        },
      }),
    )

    expect(screen.getByTestId('shared-block-frame')).toHaveAttribute('data-status', status)
    expect(screen.getByTestId('shared-block-status')).toHaveTextContent(label)
  })

  it('shows no status marker once the block is in sync with live', () => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 3,
          title: 'Banner',
          usageCount: 1,
          status: 'published',
          editLink: '/admin/shared-blocks/item/3/edit',
        },
      }),
    )

    expect(screen.queryByTestId('shared-block-status')).not.toBeInTheDocument()
  })

  it('offers publish only while the block has unpublished work', async () => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 3,
          title: 'Banner',
          usageCount: 1,
          status: 'modified',
          editLink: '/admin/shared-blocks/item/3/edit',
        },
      }),
    )

    expect(await openMenu()).toContain('Publish shared block')
  })

  it('omits publish for an already-published block', async () => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 3,
          title: 'Banner',
          usageCount: 1,
          status: 'published',
          editLink: '/admin/shared-blocks/item/3/edit',
        },
      }),
    )

    expect(await openMenu()).not.toContain('Publish shared block')
  })

  it('publishes the block only after the confirmation is accepted', async () => {
    renderFrame(
      createSharedBlockReferenceNode({
        sharedBlock: {
          blockId: 7,
          title: 'Banner',
          usageCount: 3,
          status: 'modified',
          editLink: '/admin/shared-blocks/item/7/edit',
        },
      }),
    )

    await openMenu()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Publish shared block' }))

    // The dialog quotes the blast radius before anything is sent.
    expect(screen.getByRole('dialog')).toHaveTextContent('3 pages')
    expect(mutationCalls('/admin/grid-shared-blocks/api/setPublished')).toHaveLength(0)

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))

    const [url, init] = mutationCalls('/admin/grid-shared-blocks/api/setPublished')[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/setPublished')
    expect(JSON.parse(String(init?.body))).toEqual({ blockId: 7, published: true })
  })

  it('detaches only after the confirmation is accepted', async () => {
    const node = createSharedBlockReferenceNode({ id: 42 })
    renderFrame(node)

    await openMenu()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Detach into this page' }))
    expect(mutationCalls('/admin/grid-shared-blocks/api/detach')).toHaveLength(0)

    await userEvent.click(screen.getByRole('button', { name: 'Detach' }))

    const [url, init] = mutationCalls('/admin/grid-shared-blocks/api/detach')[0]
    expect(url).toBe('/admin/grid-shared-blocks/api/detach')
    expect(JSON.parse(String(init?.body))).toEqual({ element: { type: 'element', id: 42 } })
  })

  it('hides move up on the first sibling and move down on the last', async () => {
    const first = createSharedBlockReferenceNode({ id: 10 })
    const last = createSharedBlockReferenceNode({ id: 20 })

    renderFrame(first, [first, last])
    const firstItems = await openMenu()

    expect(firstItems).not.toContain('Move up')
    expect(firstItems).toContain('Move down')
  })

  it('hides both move actions when the placement is the only sibling', async () => {
    const only = createSharedBlockReferenceNode({ id: 10 })

    renderFrame(only)
    const items = await openMenu()

    expect(items).not.toContain('Move up')
    expect(items).not.toContain('Move down')
  })

  // The frame's menu is the ONLY way to move a placement — placements are
  // deliberately not draggable — so the anchor arithmetic below is the whole
  // feature. Asserting the menu items exist never exercised it.
  it('moves up by anchoring on the sibling BEFORE its predecessor', async () => {
    const first = createSharedBlockReferenceNode({ id: 10 })
    const middle = createSharedBlockReferenceNode({ id: 20 })
    const last = createSharedBlockReferenceNode({ id: 30 })

    renderFrameWithTree(middle, [first, middle, last])
    await openMenu()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Move up' }))

    const [url, init] = mutationCalls('api/reorder')[0]
    expect(url).toBe('/admin/grid/api/reorder')
    expect(JSON.parse(String(init?.body))).toEqual({
      element: { type: 'element', id: 20 },
      parent: middle.parent,
      after: null,
    })
  })

  it('moves the third placement up to sit after the first', async () => {
    const first = createSharedBlockReferenceNode({ id: 10 })
    const middle = createSharedBlockReferenceNode({ id: 20 })
    const last = createSharedBlockReferenceNode({ id: 30 })

    renderFrameWithTree(last, [first, middle, last])
    await openMenu()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Move up' }))

    expect(JSON.parse(String(mutationCalls('api/reorder')[0][1]?.body)).after).toEqual({
      type: 'element',
      id: 10,
    })
  })

  it('moves down by anchoring on the sibling it swaps with', async () => {
    const first = createSharedBlockReferenceNode({ id: 10 })
    const last = createSharedBlockReferenceNode({ id: 20 })

    renderFrameWithTree(first, [first, last])
    await openMenu()
    await userEvent.click(screen.getByRole('menuitem', { name: 'Move down' }))

    expect(JSON.parse(String(mutationCalls('api/reorder')[0][1]?.body))).toEqual({
      element: { type: 'element', id: 10 },
      parent: first.parent,
      after: { type: 'element', id: 20 },
    })
  })

  it('renders no actions at all in a readonly host', () => {
    renderWithProviders(
      <SharedBlockFrame node={createSharedBlockReferenceNode()} siblings={[]} readonly>
        <p>block content</p>
      </SharedBlockFrame>,
    )

    expect(screen.queryByTestId('actions-menu-trigger')).not.toBeInTheDocument()
    expect(screen.getByTestId('shared-block-frame')).toHaveTextContent('block content')
  })

  it('still frames a block whose content is empty', () => {
    renderFrame(createSharedBlockReferenceNode({ children: [] }))

    expect(screen.getByTestId('shared-block-frame')).toBeInTheDocument()
  })

  it('renders a section root inside the frame body', () => {
    const node = createSharedBlockReferenceNode({
      root: createSectionNode({ title: 'Shared hero' }),
    })

    renderWithProviders(
      <SharedBlockFrame node={node} siblings={[node]}>
        <p>Shared hero</p>
      </SharedBlockFrame>,
    )

    const body = screen.getByTestId('shared-block-frame').querySelector('.ssgrid-shared-block-body')
    expect(body).toHaveTextContent('Shared hero')
  })
})
