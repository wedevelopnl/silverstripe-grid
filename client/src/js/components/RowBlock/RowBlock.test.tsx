import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { createRowNode } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { createCollapseStateStub, renderWithProviders } from '@/testing/renderWithProviders'

import EditableRowBlock from './EditableRowBlock'
import ReadonlyRowBlock from './ReadonlyRowBlock'

const defaultSortable = {
  attributes: {},
  listeners: {},
  setNodeRef: vi.fn(),
  transform: null,
  transition: undefined,
  isDragging: false,
  isOver: false,
}

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: vi.fn(() => ({ ...defaultSortable })),
  SortableContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  verticalListSortingStrategy: {},
  horizontalListSortingStrategy: {},
}))

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: vi.fn(() => ({ activeType: null, pendingActive: false })),
}))

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<
    typeof useSortable
  >)
  vi.mocked(useDragContext).mockReturnValue({ activeType: null, pendingActive: false })
})

describe('EditableRowBlock', () => {
  it('renders row title', () => {
    mockFetchSuccess({})

    const row = createRowNode({ title: 'Main Row' })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('renders child columns', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getAllByTestId('column-block')).toHaveLength(3)
  })

  it('shows empty state when no children', () => {
    mockFetchSuccess({})

    const row = createRowNode({ children: null })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No columns yet')).toBeInTheDocument()
  })

  it('shows empty state when children is an empty array', () => {
    mockFetchSuccess({})

    const row = createRowNode({ children: [] as never })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
  })

  it('flanks the columns with the start/end "+" insert squares when columns exist', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 1 })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
    // A single column has no internal gutter, so no "between" handle.
    expect(screen.queryByTestId('column-insert-between')).not.toBeInTheDocument()
  })

  it('renders a "between" column-insert handle in each internal gutter', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<EditableRowBlock row={row} />)

    // 3 columns → 2 internal gutters → 2 "between" handles, plus the 2 edge squares.
    expect(screen.getAllByTestId('column-insert-between')).toHaveLength(2)
    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
  })

  describe('status and state attributes', () => {
    it('includes draft status attribute', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'draft' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'draft')
    })

    it('includes modified status attribute', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'modified' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'modified')
    })

    it('includes published status attribute by default', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'published' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'published')
    })

    it('sets data-collapsed when the row is collapsed in the context', () => {
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />, {
        collapsedKeys: [row.nodeKey],
      })

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
    })

    it('does not set data-collapsed when expanded', () => {
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-collapsed')
    })

    it('sets data-drop-target when isOver and activeType is row', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row', pendingActive: false })
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-drop-target', '')
    })

    it('does not set data-drop-target when isOver but activeType is not row', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section', pendingActive: false })
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-drop-target')
    })

    it('does not set data-drop-target when activeType is row but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: false,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row', pendingActive: false })
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('layout mode', () => {
    it('uses flex layout when offset strategy is margin', () => {
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
    })

    it('uses grid layout when offset strategy is grid-placement', () => {
      // Override adapter config for this test
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
    })

    it('sets --grid-columns CSS variable in grid mode', () => {
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })

    it('does not set --grid-columns CSS variable in flex mode', () => {
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })
  })

  it('renders edit link when editLink is set', () => {
    mockFetchSuccess({})

    const row = createRowNode({ editLink: '/admin/pages/edit/show/10' })

    renderWithProviders(<EditableRowBlock row={row} />)

    const link = screen.getByTestId('row-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/10')
    expect(link).toHaveTextContent(row.title)
  })

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({})

    const row = createRowNode({ editLink: null })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title)
  })

  describe('drag handle label', () => {
    it('names the drag handle with the row title', () => {
      mockFetchSuccess({})

      // No child columns, so the only DragHandle in the tree is the row's own.
      const row = createRowNode({ title: 'Hero Row', children: null })

      renderWithProviders(<EditableRowBlock row={row} />)

      // The DragHandle exposes its label as the button's accessible name; the
      // `t(...MOVE_LABEL, 'Move {title}', { title })` call must substitute the
      // row title. Empty-string and dropped-params mutants change this text.
      expect(screen.getByTestId('drag-handle')).toHaveAccessibleName('Move Hero Row')
    })
  })

  describe('collapse toggle', () => {
    it('toggles the row collapse state with the row key when clicked', async () => {
      mockFetchSuccess({})

      // No child columns, so the only CollapseToggle is the row's own.
      const row = createRowNode({ children: null })
      const collapseState = createCollapseStateStub()

      renderWithProviders(<EditableRowBlock row={row} />, { collapseState })

      // `onToggle = useCallback(() => toggle(row.nodeKey), ...)` — a mutant that
      // drops the body must leave `toggle` uncalled on click.
      await userEvent.click(screen.getByTestId('collapse-toggle'))

      expect(collapseState.toggle).toHaveBeenCalledWith(row.nodeKey)
    })
  })

  describe('modified indicator', () => {
    it('renders the indicator with its label when status is modified', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'modified' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-modified-badge')).toHaveTextContent('Modified')
    })

    it('does not render the indicator when status is not modified', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'draft' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.queryByTestId('row-modified-badge')).not.toBeInTheDocument()
    })
  })

  describe('column count meta', () => {
    it('renders the column count text when the row has columns', () => {
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 3 })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-column-count')).toHaveTextContent('3 columns')
    })

    it('does not render the column count meta when children is an empty array', () => {
      mockFetchSuccess({})

      const row = createRowNode({ children: [] as never })

      renderWithProviders(<EditableRowBlock row={row} />)

      // `columnCount > 0` is false for an empty array, so no meta. A `>= 0`
      // mutant would (wrongly) render "0 columns" here.
      expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
    })

    it('does not render the column count meta when children is null', () => {
      mockFetchSuccess({})

      const row = createRowNode({ children: null })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
    })
  })
})

describe('ReadonlyRowBlock', () => {
  it('renders child columns', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 2 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    // ReadonlyRowBlock maps `row.children` to <ReadonlyColumnBlock>; a mutant
    // that turns the map callback into `() => undefined` renders no columns.
    expect(screen.getAllByTestId('column-block')).toHaveLength(2)
  })

  it('sets data-collapsed to empty string when collapsed', () => {
    mockFetchSuccess({})

    const row = createRowNode({})

    renderWithProviders(<ReadonlyRowBlock row={row} />, { collapsedKeys: [row.nodeKey] })

    expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
  })

  it('does not set data-collapsed when expanded', () => {
    mockFetchSuccess({})

    const row = createRowNode({})

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-collapsed')
  })

  it('renders the modified indicator with its label when status is modified', () => {
    mockFetchSuccess({})

    const row = createRowNode({ status: 'modified' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-modified-badge')).toHaveTextContent('Modified')
  })

  it('does not render the modified indicator when status is not modified', () => {
    mockFetchSuccess({})

    const row = createRowNode({ status: 'draft' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-modified-badge')).not.toBeInTheDocument()
  })

  it('renders the column count text when the row has columns', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 2 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-column-count')).toHaveTextContent('2 columns')
  })

  it('does not render the column count meta when children is an empty array', () => {
    mockFetchSuccess({})

    const row = createRowNode({ children: [] as never })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
  })

  it('renders title as plain text even when editLink is set', () => {
    mockFetchSuccess({})

    const row = createRowNode({ editLink: '/admin/pages/edit/show/10' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title)
  })

  it('does not render the start/end insert squares or the row drag handle', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 1 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('column-insert-start')).not.toBeInTheDocument()
    expect(screen.queryByTestId('column-insert-end')).not.toBeInTheDocument()
    expect(screen.queryByTestId('drag-handle')).not.toBeInTheDocument()
  })

  describe('layout mode', () => {
    // Pins the `getOffsetStrategy() === 'margin' ? 'flex' : 'grid'` ternary and
    // the `layoutMode === 'grid'` guard on the readonly path.

    it('uses flex layout and omits --grid-columns when offset strategy is margin', () => {
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<ReadonlyRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })

    it('uses grid layout and sets --grid-columns when offset strategy is grid-placement', () => {
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<ReadonlyRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })
  })
})
