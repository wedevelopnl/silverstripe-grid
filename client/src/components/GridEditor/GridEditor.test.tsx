import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import { createSectionNode, createTreeApiResponse, resetIdCounter } from '@/testing/factories'
import { mockFetchError, mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import GridEditor from './GridEditor'

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
    MeasuringStrategy: { Always: 'always' },
  }
})

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: () => ({ activeType: null, pendingActive: false }),
  DragContext: {
    Provider: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  },
  useDragAndDrop: () => ({
    dndContextProps: {},
    dragState: null,
    pendingTree: null,
  }),
}))

describe('GridEditor', () => {
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
    // Append should not be shown
    expect(screen.queryByTestId('add-child-append')).not.toBeInTheDocument()
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
})
