import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createSectionNode,
  createSharedBlockReferenceNode,
  resetIdCounter,
} from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import { selectSections } from './selectSections'
import EditableGridEditor from './EditableGridEditor'

vi.mock('@dnd-kit/sortable', async () =>
  (await import('@/testing/mockDndKit')).mockSortableModule(),
)

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const { mockDndCoreModule, Passthrough } = await import('@/testing/mockDndKit')
  return mockDndCoreModule(await importOriginal(), { DragOverlay: Passthrough })
})

beforeEach(() => {
  resetIdCounter()
})

describe('selectSections', () => {
  it('keeps placements alongside sections in server order', () => {
    const first = createSectionNode({ id: 10, parent: { type: 'page', id: 1 } })
    const placement = createSharedBlockReferenceNode({ id: 20, parent: { type: 'page', id: 1 } })
    const last = createSectionNode({ id: 30, parent: { type: 'page', id: 1 } })

    const roots = selectSections({
      rootParent: { type: 'page', id: 1 },
      nodes: [first, placement, last],
    })

    expect(roots.map((node) => node.nodeKey)).toEqual([
      first.nodeKey,
      placement.nodeKey,
      last.nodeKey,
    ])
  })

  it('returns nothing while the tree is still loading', () => {
    expect(selectSections(undefined)).toEqual([])
  })
})

function wireBase(id: number, type: string, parent: { type: string; id: number }, title: string) {
  return {
    self: { type, id },
    parent,
    title,
    blockSchema: { typeName: 'X', label: 'X', icon: 'i', type: 'X', title },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: true,
    canCreate: true,
    editLink: null,
    status: 'published',
  }
}

function wireSection(id: number, title: string) {
  return {
    ...wireBase(id, 'section', { type: 'page', id: 1 }, title),
    containerType: 'section',
    children: [],
  }
}

describe('EditableGridEditor with a shared block placement', () => {
  it('frames the placement between its plain section siblings', async () => {
    // Served as a wire payload so the test covers normalisation too: the
    // placement has to survive schema parsing to reach the frame at all.
    mockFetchSuccess({
      rootParent: { type: 'page', id: 1 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        wireSection(10, 'Local top'),
        {
          ...wireBase(20, 'element', { type: 'page', id: 1 }, 'Banner'),
          sharedBlock: {
            blockId: 5,
            title: 'Banner',
            usageCount: 2,
            status: 'published',
            editLink: '/admin/shared-blocks/item/5/edit',
          },
          children: [
            {
              ...wireBase(21, 'section', { type: 'element', id: 20 }, 'Shared hero'),
              containerType: 'section',
              children: [],
            },
          ],
        },
        wireSection(30, 'Local bottom'),
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    expect(await screen.findByTestId('shared-block-frame')).toBeInTheDocument()

    // The block's own section renders through the ordinary section component,
    // inside the frame — the siblings stay outside it.
    expect(screen.getByTestId('shared-block-frame')).toHaveTextContent('Shared hero')
    expect(screen.getByTestId('shared-block-frame')).not.toHaveTextContent('Local top')
    expect(screen.getByText('Local top')).toBeInTheDocument()
    expect(screen.getByText('Local bottom')).toBeInTheDocument()
  })
})

describe('EditableGridEditor rooted at a shared block', () => {
  it('reads the block-rooted tree endpoint, not the page one', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    await screen.findByTestId('grid-editor')

    const urls = getFetchCalls().map(([url]) => String(url))
    expect(urls).toContain('/admin/grid-shared-blocks/api/readTree/9')
    expect(urls.some((url) => url.includes('/readTree/9/'))).toBe(false)
  })

  it('offers the add affordance while the block is still empty', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    expect(await screen.findByTestId('add-child-button')).toBeInTheDocument()
  })

  // A page zone only ever roots Sections, so the root renderer used to draw
  // nothing else — which left a row-, column- or leaf-rooted block showing an
  // empty editor, with its content reachable nowhere.
  it.each([
    ['row', 'row-block'],
    ['column', 'column-block'],
  ])('renders a %s-rooted block through that shape’s own component', async (shape, testId) => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        {
          ...wireBase(40, shape, { type: 'sharedBlock', id: 9 }, `Shared ${shape}`),
          containerType: shape,
          ...(shape === 'column'
            ? { gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} } }
            : {}),
          children: [],
        },
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    expect(await screen.findByTestId(testId)).toBeInTheDocument()
    expect(screen.getByTestId(testId)).toHaveTextContent(`Shared ${shape}`)
  })

  // The root is the only node at its level and every slot below it belongs to
  // a different shape, so a drag from it could never land anywhere.
  it.each([
    ['section', 'section-block'],
    ['row', 'row-block'],
    ['column', 'column-block'],
  ])('gives a %s-rooted block no drag handle on its root', async (shape, testId) => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        {
          ...wireBase(40, shape, { type: 'sharedBlock', id: 9 }, `Shared ${shape}`),
          containerType: shape,
          ...(shape === 'column'
            ? { gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} } }
            : {}),
          children: [],
        },
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    await screen.findByTestId(testId)
    expect(screen.queryByTestId('drag-handle')).toBeNull()
  })

  it('draws no area-level chrome — every action there names a page', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        {
          ...wireBase(40, 'section', { type: 'sharedBlock', id: 9 }, 'Shared section'),
          containerType: 'section',
          children: [],
        },
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    await screen.findByTestId('section-block')

    for (const name of [
      'Reset changes',
      'Collapse all sections',
      'Open page',
      'Remove all sections',
    ]) {
      expect(screen.queryByRole('button', { name })).toBeNull()
    }

    // The region heading survives — it is the only thing left, so the strip
    // itself goes visually hidden rather than sitting empty above the canvas.
    expect(screen.getByRole('heading', { name: 'Grid area' })).toBeInTheDocument()
    expect(screen.getByTestId('grid-editor-header')).toHaveClass('ssgrid-visually-hidden')
  })

  it('keeps the drag handle on content inside the block', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        {
          ...wireBase(40, 'section', { type: 'sharedBlock', id: 9 }, 'Shared section'),
          containerType: 'section',
          children: [
            {
              ...wireBase(41, 'row', { type: 'section', id: 40 }, 'Shared row'),
              containerType: 'row',
              children: [],
            },
          ],
        },
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    await screen.findByTestId('row-block')

    // Exactly one: the row's. The section above it is the block's root.
    const handles = screen.getAllByTestId('drag-handle')
    expect(handles).toHaveLength(1)
    expect(within(screen.getByTestId('row-block')).getByTestId('drag-handle')).toBe(handles[0])
  })

  it('renders a leaf-rooted block as the element card itself, handle-less', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [wireBase(41, 'element', { type: 'sharedBlock', id: 9 }, 'Shared paragraph')],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    expect(await screen.findByTestId('element-card-title')).toHaveTextContent('Shared paragraph')
    expect(screen.queryByTestId('drag-handle')).toBeNull()
  })
})

/**
 * A placement carrying a full Section → Row → Column → leaf subtree, next to a
 * page-local section of the same shape — the contrast the read-only assertions
 * rely on.
 */
function readonlySubtreePayload() {
  return {
    rootParent: { type: 'page', id: 1 },
    allowedTypes: {
      section: {},
      row: {},
      column: { 'App\\Text': { label: 'Text', icon: 'i', description: '' } },
    },
    nodes: [
      {
        ...wireBase(20, 'element', { type: 'page', id: 1 }, 'Banner'),
        sharedBlock: {
          blockId: 5,
          title: 'Shared Banner',
          usageCount: 2,
          status: 'published',
          editLink: '/admin/shared-blocks/item/5/edit',
        },
        children: [
          {
            ...wireBase(21, 'section', { type: 'element', id: 20 }, 'Shared hero'),
            containerType: 'section',
            editLink: '/admin/shared-blocks/edit/21',
            children: [
              {
                ...wireBase(22, 'row', { type: 'section', id: 21 }, 'Shared row'),
                containerType: 'row',
                children: [
                  {
                    ...wireBase(23, 'column', { type: 'row', id: 22 }, 'Shared column'),
                    containerType: 'column',
                    gridSettings: {
                      default: { width: 6, offset: 0, visible: true },
                      overrides: {},
                    },
                    children: [wireBase(24, 'element', { type: 'column', id: 23 }, 'Shared text')],
                  },
                ],
              },
            ],
          },
        ],
      },
      {
        ...wireBase(30, 'section', { type: 'page', id: 1 }, 'Local section'),
        containerType: 'section',
        children: [
          {
            ...wireBase(31, 'row', { type: 'section', id: 30 }, 'Local row'),
            containerType: 'row',
            children: [
              {
                ...wireBase(32, 'column', { type: 'row', id: 31 }, 'Local column'),
                containerType: 'column',
                gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
                children: [wireBase(33, 'element', { type: 'column', id: 32 }, 'Local text')],
              },
            ],
          },
        ],
      },
    ],
  }
}

describe('a placed shared block renders read-only', () => {
  beforeEach(() => {
    HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
      this.setAttribute('open', '')
    })
    HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
      this.removeAttribute('open')
    })
  })

  it('renders no editing controls inside the frame while the local section keeps all of them', async () => {
    mockFetchSuccess(readonlySubtreePayload())

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    const inFrame = within(await screen.findByTestId('shared-block-frame'))

    // Nothing inside a block is draggable, addable or actionable from a page.
    expect(inFrame.queryAllByTestId('drag-handle')).toHaveLength(0)
    expect(inFrame.queryAllByTestId('add-child-button')).toHaveLength(0)
    expect(inFrame.queryAllByTestId('add-content-button')).toHaveLength(0)
    expect(inFrame.queryAllByTestId('column-insert-start')).toHaveLength(0)
    expect(inFrame.queryAllByTestId('column-insert-end')).toHaveLength(0)
    expect(inFrame.queryAllByTestId('element-toolbar')).toHaveLength(0)
    // The frame BAR keeps its own menu (publish/move/detach) — the block's
    // content below it offers none.
    expect(inFrame.getAllByTestId('actions-menu-trigger')).toHaveLength(1)

    // The local section still edits like before.
    const local = within(
      screen.getByText('Local section').closest('[data-testid="section-block"]') as HTMLElement,
    )
    expect(local.getAllByTestId('drag-handle').length).toBeGreaterThan(0)
    expect(local.getAllByTestId('element-toolbar').length).toBeGreaterThan(0)
    expect(local.getAllByTestId('add-child-button').length).toBeGreaterThan(0)
  })

  it('keeps the column size and offset pickers visible but disabled inside the frame', async () => {
    mockFetchSuccess(readonlySubtreePayload())

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    const inFrame = within(await screen.findByTestId('shared-block-frame'))

    // Asserted via the DOM property, not jest-dom's toBeDisabled — biome's
    // noPlaywrightMissingAwait misreads that matcher as Playwright's async one
    // in this file.
    expect(inFrame.getByTestId('column-badge')).toHaveProperty('disabled', true)
    expect(inFrame.getByTestId('column-offset-badge')).toHaveProperty('disabled', true)
  })

  it('gives the frame bar exactly open, edit and remove, pointing at the block', async () => {
    mockFetchSuccess(readonlySubtreePayload())

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    const inFrame = within(await screen.findByTestId('shared-block-frame'))
    const toolbar = inFrame.getByTestId('shared-placement-toolbar')

    expect(within(toolbar).getByTestId('element-action-open')).toBeEnabled()
    expect(within(toolbar).getByTestId('element-action-edit')).toBeEnabled()
    expect(within(toolbar).getByTestId('element-action-remove')).toBeEnabled()
    expect(toolbar.querySelectorAll('button')).toHaveLength(3)

    // One toolbar for the whole placement, and it lives on the frame's BAR —
    // not on the shared section below it, whose own editLink
    // (/admin/shared-blocks/edit/21) points at the element, not the block.
    expect(inFrame.getAllByTestId('shared-placement-toolbar')).toHaveLength(1)
    expect(
      within(screen.getByTestId('shared-block-actions')).getByTestId('shared-placement-toolbar'),
    ).toBe(toolbar)
  })

  it('remove archives the placement itself, leaving the block untouched', async () => {
    const user = userEvent.setup()
    mockFetchSuccess(readonlySubtreePayload())

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    const inFrame = within(await screen.findByTestId('shared-block-frame'))
    await user.click(inFrame.getByTestId('element-action-remove'))

    const dialog = screen.getByRole('dialog')
    expect(dialog).toHaveTextContent('Shared Banner')
    await user.click(within(dialog).getByRole('button', { name: 'Remove' }))

    await waitFor(() => {
      const deleteCall = getFetchCalls().find(([, init]) => init?.method === 'DELETE')
      expect(deleteCall).toBeDefined()
      // The placement's identity (element 20), never the block root's (21).
      expect(String(deleteCall?.[0])).toContain('/api/delete')
      expect(String(deleteCall?.[0])).toContain('id=20')
    })
  })

  it('keeps the library editor fully editable — its tree is block-rooted, not a placement', async () => {
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 5 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [
        {
          ...wireBase(21, 'section', { type: 'sharedBlock', id: 5 }, 'Shared hero'),
          containerType: 'section',
          children: [],
        },
      ],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 5 }} />)

    await screen.findByText('Shared hero')

    expect(screen.getAllByTestId('element-toolbar').length).toBeGreaterThan(0)
    expect(screen.queryByTestId('shared-placement-toolbar')).not.toBeInTheDocument()

    // Not the drag handle: the root has none by design (see the drag-handle
    // tests above). What proves this tree is editable rather than a placement
    // is its own toolbar, and the absence of the placement bar that would
    // otherwise own every action.
    expect(screen.queryByTestId('drag-handle')).toBeNull()
  })
})

describe('shared block placement affordances', () => {
  beforeEach(() => {
    HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
      this.setAttribute('open', '')
    })
    HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
      this.removeAttribute('open')
    })
  })

  it('offers "add shared block" at the root of a page zone', async () => {
    mockFetchSuccess({
      rootParent: { type: 'page', id: 1 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'main' }} />)

    expect(await screen.findByTestId('add-child-shared-trigger')).toBeInTheDocument()
  })

  it('opens the picker scoped to the page and zone', async () => {
    mockFetchSuccess({
      rootParent: { type: 'page', id: 1 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'page', pageId: 1, zone: 'sidebar' }} />)
    await userEvent.click(await screen.findByTestId('add-child-shared-trigger'))
    await userEvent.click(screen.getByRole('menuitem', { name: 'Place shared section…' }))

    expect(screen.getByTestId('shared-block-picker')).toBeInTheDocument()

    const listCall = getFetchCalls().find(([url]) => String(url).includes('api/list?'))
    expect(listCall?.[0]).toBe('/admin/grid-shared-blocks/api/list?parentType=page')
  })

  it('never offers a placement inside the library editor', async () => {
    // A block may not contain another block.
    mockFetchSuccess({
      rootParent: { type: 'sharedBlock', id: 9 },
      allowedTypes: { section: {}, row: {}, column: {} },
      nodes: [],
    })

    renderWithProviders(<EditableGridEditor root={{ kind: 'sharedBlock', blockId: 9 }} />)

    await screen.findByTestId('add-child-button')
    expect(screen.queryByTestId('add-child-shared-trigger')).not.toBeInTheDocument()
  })
})
