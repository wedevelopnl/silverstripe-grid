import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createSectionNode, createTreeApiResponse, resetIdCounter } from '@/testing/factories'
import { mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import { NodeIdentity } from '@/types/identity'

import GridEditor from './GridEditor'

/**
 * The leading insert slots, narrowed to the page-level one. Every rendered
 * section also carries a row-level `before-first` slot, so a bare testid query
 * would count those too — the label is what separates the two levels.
 */
function leadingSectionSlots(): HTMLElement[] {
  return screen
    .queryAllByTestId('add-child-before-first')
    .filter((slot) => slot.textContent?.includes('Add Section'))
}

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: () => ({
    attributes: {},
    listeners: {},
    setNodeRef: vi.fn(),
    transform: null,
    transition: undefined,
    isDragging: false,
    isOver: false,
  }),
  SortableContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  verticalListSortingStrategy: {},
  horizontalListSortingStrategy: {},
}))

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@dnd-kit/core')>()
  return {
    ...actual,
    DndContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    DragOverlay: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  }
})

// Capture the `onReorder` callback GridEditor hands to useDragAndDrop so a test
// can invoke it directly (the real hook is otherwise mocked out). This is the
// only seam to exercise GridEditor's onReorder guard without a full drag.
type OnReorderFn = (
  element: unknown,
  parent: unknown,
  after: unknown,
  clearPendingTree: () => void,
) => void
const capturedOnReorder: { current: OnReorderFn | null } = { current: null }

// Mutable override for the mocked hook's return so individual tests can drive a
// non-null dragState (DragOverlay rendering) without unmocking the DnD stack.
const dragAndDropReturn: { dragState: unknown; pendingTree: unknown } = {
  dragState: null,
  pendingTree: null,
}

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: () => ({ activeType: null, pendingActive: false }),
  DragContext: {
    Provider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  },
  useDragAndDrop: (opts: { onReorder: OnReorderFn }) => {
    capturedOnReorder.current = opts.onReorder
    return {
      dndContextProps: {},
      dragState: dragAndDropReturn.dragState,
      pendingTree: dragAndDropReturn.pendingTree,
    }
  },
}))

const reorderMutate = vi.fn()
vi.mock('@/hooks/useElementMutations', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/hooks/useElementMutations')>()
  return {
    ...actual,
    useReorderElement: () => ({ mutate: reorderMutate }),
  }
})

describe('GridEditor', () => {
  beforeEach(() => {
    capturedOnReorder.current = null
    reorderMutate.mockClear()
    dragAndDropReturn.dragState = null
    dragAndDropReturn.pendingTree = null
  })

  it('shows loading state with specific text', () => {
    // Fetch that never resolves keeps the query in loading state
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    const loadingEl = screen.getByTestId('grid-editor-loading')
    expect(loadingEl).toHaveTextContent('Loading elements...')
  })

  it('renders sections after data loads', async () => {
    resetIdCounter()

    const treeResponse = createTreeApiResponse({
      pageId: 1,
      sections: [
        createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
        createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
      ],
    })

    mockFetchSuccess(treeResponse)

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    await waitFor(() => {
      expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
    })

    expect(screen.getAllByTestId('section-block')).toHaveLength(2)
  })

  it('shows append AddChildButton when sections exist', async () => {
    resetIdCounter()

    const treeResponse = createTreeApiResponse({
      pageId: 1,
      sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
    })

    mockFetchSuccess(treeResponse)

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    await waitFor(() => {
      expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
    })

    // When sections exist, append variant is shown (not empty-state)
    const appendButtons = screen.getAllByTestId('add-child-append')
    // There may be multiple (section's own append button + editor's append button)
    // The editor's append button is at the section level
    expect(appendButtons.length).toBeGreaterThan(0)
  })

  it('shows empty state when tree has no sections', async () => {
    const treeResponse = createTreeApiResponse({
      pageId: 1,
      sections: [],
    })

    mockFetchSuccess(treeResponse)

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    await waitFor(() => {
      expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
    })

    // When tree is empty, the empty-state AddChildButton is shown
    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No sections yet')).toBeInTheDocument()
    // Neither of the with-children slots should be shown
    expect(screen.queryByTestId('add-child-append')).not.toBeInTheDocument()
    expect(leadingSectionSlots()).toHaveLength(0)
  })

  it('shows error state when fetch fails', async () => {
    mockFetchError(500, { message: 'Internal Server Error' })

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    await waitFor(() => {
      expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
    })

    const errorEl = screen.getByTestId('grid-editor-error')
    expect(errorEl).toBeInTheDocument()
    expect(errorEl).toHaveTextContent(/Failed to load elements/)
  })

  it('sets data attributes on root element', () => {
    vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

    renderWithProviders(<GridEditor pageId={1} zone="main" />)

    const editor = screen.getByTestId('grid-editor')
    expect(editor).toHaveAttribute('data-page-id', '1')
    expect(editor).toHaveAttribute('data-zone', 'main')
  })

  describe('grid area header', () => {
    it('toggles between "collapse all" and "expand all"', async () => {
      resetIdCounter()
      const user = userEvent.setup()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [
            createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
            createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
          ],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      const collapseAll = await screen.findByRole('button', { name: 'Collapse all sections' })
      await user.click(collapseAll)

      // Every section is now collapsed, so the button flips to expand-all.
      const expandAll = screen.getByRole('button', { name: 'Expand all sections' })
      expect(
        screen.queryByRole('button', { name: 'Collapse all sections' }),
      ).not.toBeInTheDocument()

      await user.click(expandAll)
      expect(screen.getByRole('button', { name: 'Collapse all sections' })).toBeInTheDocument()
    })

    it('disables the collapse/expand-all toggle in readonly mode', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={5} />)

      const collapseAll = await screen.findByRole('button', { name: 'Collapse all sections' })
      expect(collapseAll).toBeDisabled()
    })
  })

  describe('readonly mode', () => {
    it('renders sections in readonly mode with readonly class', async () => {
      resetIdCounter()

      const treeResponse = createTreeApiResponse({
        pageId: 1,
        sections: [
          createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
          createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
        ],
      })

      mockFetchSuccess(treeResponse)

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={5} />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      expect(screen.getAllByTestId('section-block')).toHaveLength(2)
      expect(screen.getByTestId('grid-editor')).toHaveAttribute('data-readonly', '')
    })

    it('mounts the viewport switcher in readonly mode without the reset-overrides button', async () => {
      resetIdCounter()

      const treeResponse = createTreeApiResponse({
        pageId: 1,
        sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
      })

      mockFetchSuccess(treeResponse)

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={5} />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      // ViewportSwitcher is mounted so admins can inspect the grid at
      // each responsive breakpoint while browsing history.
      expect(screen.getByTestId('viewport-switcher')).toBeInTheDocument()
      // But its reset-overrides button is gated on readonly and must
      // be absent in history view.
      expect(screen.queryByTestId('reset-overrides-button')).not.toBeInTheDocument()
    })

    it('does not render any drag handles in readonly mode', async () => {
      resetIdCounter()

      const treeResponse = createTreeApiResponse({
        pageId: 1,
        sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
      })

      mockFetchSuccess(treeResponse)

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={5} />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      // The readonly block variants don't call useSortable, so no
      // DragHandle is rendered anywhere in the tree.
      expect(screen.queryAllByTestId('drag-handle')).toHaveLength(0)
    })

    it('shows empty state message when readonly tree has no sections', async () => {
      const treeResponse = createTreeApiResponse({
        pageId: 1,
        sections: [],
      })

      mockFetchSuccess(treeResponse)

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={3} />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      expect(screen.getByText('No sections in this version')).toBeInTheDocument()
      expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
    })

    it('shows loading state in readonly mode with readonly class', () => {
      vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

      renderWithProviders(<GridEditor pageId={1} zone="main" readonly={true} version={2} />)

      expect(screen.getByTestId('grid-editor-loading')).toHaveTextContent('Loading elements...')
      expect(screen.getByTestId('grid-editor')).toHaveAttribute('data-readonly', '')
    })
  })

  describe('null page id sentinel', () => {
    it('renders the no-sections empty state without mounting the editor body', () => {
      // No fetch is issued — the null guard short-circuits before any hook runs.
      renderWithProviders(<GridEditor pageId={null} zone="main" />)

      const editor = screen.getByTestId('grid-editor')
      expect(editor).toHaveAttribute('data-zone', 'main')
      // The sentinel uses the EmptyState component with the NO_SECTIONS message.
      expect(screen.getByText('No sections yet')).toBeInTheDocument()
      // The editor body (canvas + chrome) is never mounted for a null page id.
      expect(screen.queryByTestId('grid-editor-canvas')).not.toBeInTheDocument()
      expect(screen.queryByTestId('viewport-switcher')).not.toBeInTheDocument()
    })
  })

  describe('canvas modified status', () => {
    it('flags the canvas as modified when any section is modified', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [
            createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
            createSectionNode({
              id: 20,
              parent: { type: 'page', id: 1 },
              title: 'Content',
              status: 'modified',
            }),
          ],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      expect(screen.getByTestId('grid-editor-canvas')).toHaveAttribute('data-status', 'modified')
    })

    it('leaves the canvas status unset when every section is published', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [
            createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
            createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
          ],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      expect(screen.getByTestId('grid-editor-canvas')).not.toHaveAttribute('data-status')
    })
  })

  describe('between add-section buttons', () => {
    it('renders a between button in the gap of every section pair but not before the first', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [
            createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
            createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
            createSectionNode({ id: 30, parent: { type: 'page', id: 1 }, title: 'Footer' }),
          ],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      // Three sections produce two inter-section gaps (index > 0): one before
      // the second and one before the third section. The slot above the first
      // is the separate before-first button.
      expect(screen.getAllByTestId('add-child-between')).toHaveLength(2)
      expect(leadingSectionSlots()).toHaveLength(1)
    })

    it('renders no between button when there is only one section', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })

      expect(screen.queryByTestId('add-child-between')).not.toBeInTheDocument()
      // A lone section still gets a leading slot — that is the whole point of
      // before-first: prepending must not require an existing gap.
      expect(leadingSectionSlots()).toHaveLength(1)
    })
  })

  describe('error message', () => {
    it('interpolates the failure message into the load-error notice', async () => {
      mockFetchError(500, { message: 'Internal Server Error' })

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      const errorEl = await screen.findByTestId('grid-editor-error')
      // The {message} placeholder must be substituted with the real API error
      // text — a dropped interpolation arg would leave a literal "{message}".
      expect(errorEl).toHaveTextContent('Internal Server Error')
      expect(errorEl).not.toHaveTextContent('{message}')
    })
  })

  describe('loading guard', () => {
    it('does not mount the editor body while the tree is still loading', () => {
      vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      // While `data` is undefined the editor chrome must stay unmounted —
      // only the loading notice shows.
      expect(screen.getByTestId('grid-editor-loading')).toBeInTheDocument()
      expect(screen.queryByTestId('grid-editor-canvas')).not.toBeInTheDocument()
      expect(screen.queryByTestId('viewport-switcher')).not.toBeInTheDocument()
    })
  })

  describe('grid area header actions', () => {
    it('renders the disabled reset, open, and clear placeholder actions by label', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      const reset = await screen.findByRole('button', { name: 'Reset changes' })
      const open = screen.getByRole('button', { name: 'Open page' })
      const clear = screen.getByRole('button', { name: 'Remove all sections' })

      expect(reset).toBeDisabled()
      expect(open).toBeDisabled()
      expect(clear).toBeDisabled()
      // Titles mirror the accessible labels and must carry the same copy.
      expect(reset).toHaveAttribute('title', 'Reset changes')
      expect(open).toHaveAttribute('title', 'Open page')
      expect(clear).toHaveAttribute('title', 'Remove all sections')
      // aria-label is asserted independently of the role-name lookup above:
      // the role name can be satisfied by `title` alone, so an empty aria-label
      // would still match getByRole. Pin the aria-label directly so the
      // screen-reader copy can't silently collapse to "".
      expect(reset).toHaveAttribute('aria-label', 'Reset changes')
      expect(open).toHaveAttribute('aria-label', 'Open page')
      expect(clear).toHaveAttribute('aria-label', 'Remove all sections')
    })

    it('shows the area title', async () => {
      resetIdCounter()

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      expect(await screen.findByRole('heading', { name: 'Grid area' })).toBeInTheDocument()
    })

    it('keeps the toggle-all in "collapse all" mode and disabled when there are no sections', async () => {
      resetIdCounter()

      mockFetchSuccess(createTreeApiResponse({ pageId: 1, sections: [] }))

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      // With zero sections `allCollapsed` is false (the length guard fails), so
      // the toggle keeps the "collapse all" label and stays disabled.
      const toggle = await screen.findByRole('button', { name: 'Collapse all sections' })
      expect(toggle).toBeDisabled()
      expect(screen.queryByRole('button', { name: 'Expand all sections' })).not.toBeInTheDocument()
    })

    it('collapses only the still-expanded sections when toggling all from a mixed state', async () => {
      resetIdCounter()
      const user = userEvent.setup()

      // jsdom's localStorage under vitest doesn't expose a usable setItem, so
      // GridEditor's CollapseState (built from localStorage) can't be seeded
      // through the real global. Swap in a Map-backed mock to inject a mixed
      // starting state: section 10 collapsed, section 20 expanded.
      const realLocalStorage = globalThis.localStorage
      const store = new Map<string, string>([
        ['grid:collapsed:1', JSON.stringify([NodeIdentity.toKey('section', 10)])],
      ])
      const mockStorage: Storage = {
        get length() {
          return store.size
        },
        clear: () => store.clear(),
        getItem: (key) => store.get(key) ?? null,
        setItem: (key, value) => {
          store.set(key, value)
        },
        removeItem: (key) => {
          store.delete(key)
        },
        key: (index) => [...store.keys()][index] ?? null,
      }
      Object.defineProperty(globalThis, 'localStorage', {
        value: mockStorage,
        writable: true,
        configurable: true,
      })

      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [
            createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
            createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
          ],
        }),
      )

      try {
        renderWithProviders(<GridEditor pageId={1} zone="main" />)

        // Not every section is collapsed yet → button still offers "collapse all".
        const collapseAll = await screen.findByRole('button', { name: 'Collapse all sections' })
        await user.click(collapseAll)

        // Toggling all must collapse the expanded section without re-expanding
        // the already-collapsed one — so now EVERY section is collapsed and the
        // button flips to "expand all". (A guard that toggled every section
        // unconditionally would re-expand section 10 and leave a mixed state.)
        expect(
          await screen.findByRole('button', { name: 'Expand all sections' }),
        ).toBeInTheDocument()
        expect(
          screen.queryByRole('button', { name: 'Collapse all sections' }),
        ).not.toBeInTheDocument()
      } finally {
        Object.defineProperty(globalThis, 'localStorage', {
          value: realLocalStorage,
          writable: true,
          configurable: true,
        })
      }
    })
  })

  describe('reorder guard', () => {
    it('fires the reorder mutation with the loaded tree when onReorder runs after data loads', async () => {
      resetIdCounter()

      const treeResponse = createTreeApiResponse({
        pageId: 1,
        sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
      })
      mockFetchSuccess(treeResponse)

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })
      // onReorder is captured during render; with data loaded the guard passes.
      expect(capturedOnReorder.current).not.toBeNull()

      const element = { type: 'section', id: 10 }
      const parent = { type: 'page', id: 1 }
      const clear = vi.fn()
      act(() => {
        capturedOnReorder.current?.(element, parent, null, clear)
      })

      expect(reorderMutate).toHaveBeenCalledTimes(1)
      const call = reorderMutate.mock.calls[0][0]
      expect(call.params).toEqual({ element, parent, after: null })
      expect(call.clearPendingTree).toBe(clear)
      // The guard passed because `data` is defined — a loaded tree is forwarded.
      expect(call.tree).toBeDefined()
      expect(call.tree.nodes).toHaveLength(1)
    })

    it('does not fire the reorder mutation while the tree is still loading (data undefined guard)', () => {
      // Fetch never resolves → data stays undefined. EditableGridEditor still
      // mounts and useGridEditorDnd hands onReorder to the (mocked) hook, so the
      // callback exists even though the editor body JSX is unmounted. Invoking
      // it must hit the
      // `if (data === undefined) return` guard and skip the mutation entirely.
      // A mutant that drops the guard would call mutate({ tree: undefined }).
      vi.spyOn(globalThis, 'fetch').mockReturnValue(new Promise(() => {}))

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      expect(capturedOnReorder.current).not.toBeNull()

      act(() => {
        capturedOnReorder.current?.(
          { type: 'section', id: 10 },
          { type: 'page', id: 1 },
          null,
          vi.fn(),
        )
      })

      expect(reorderMutate).not.toHaveBeenCalled()
    })
  })

  describe('drag overlay', () => {
    it('renders the drag overlay preview only while an element is being dragged', async () => {
      resetIdCounter()

      // dragState non-null → the `dragState !== null && <DragOverlayContent />`
      // guard must render the preview. A mutant that forces the guard to `false`
      // would suppress the preview even mid-drag.
      const section = createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })
      dragAndDropReturn.dragState = {
        activeId: 'section-10',
        activeType: 'section',
        activeNode: section,
      }

      mockFetchSuccess(createTreeApiResponse({ pageId: 1, sections: [section] }))

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      // The DragOverlayContent for a section renders its title preview.
      expect(await screen.findByTestId('drag-overlay-section-title')).toBeInTheDocument()
    })

    it('renders no drag overlay preview when nothing is being dragged', async () => {
      resetIdCounter()
      // dragState stays null (default) → no preview.
      mockFetchSuccess(
        createTreeApiResponse({
          pageId: 1,
          sections: [createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' })],
        }),
      )

      renderWithProviders(<GridEditor pageId={1} zone="main" />)

      await waitFor(() => {
        expect(screen.queryByTestId('grid-editor-loading')).not.toBeInTheDocument()
      })
      expect(screen.queryByTestId('drag-overlay-section-title')).not.toBeInTheDocument()
    })
  })
})
