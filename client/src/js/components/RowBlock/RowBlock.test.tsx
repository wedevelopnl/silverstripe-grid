import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { createRowNode } from '@/testing/factories'
import { defaultSortable, resetDndMocks } from '@/testing/mockDndKit'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { createCollapseStateStub, renderWithProviders } from '@/testing/renderWithProviders'

import EditableRowBlock from './EditableRowBlock'
import ReadonlyRowBlock from './ReadonlyRowBlock'

vi.mock('@dnd-kit/sortable', async () =>
  (await import('@/testing/mockDndKit')).mockSortableModule(),
)

vi.mock('@/hooks/useDragAndDrop', async () =>
  (await import('@/testing/mockDndKit')).mockUseDragContextModule(),
)

beforeEach(() => {
  mockFetchSuccess({})
})

afterEach(() => {
  resetDndMocks({ useSortable, useDragContext })
})

describe('EditableRowBlock', () => {
  it('renders row title', () => {
    const row = createRowNode({ title: 'Main Row' })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('renders child columns', () => {
    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getAllByTestId('column-block')).toHaveLength(3)
  })

  it('shows empty state when no children', () => {
    const row = createRowNode({ children: null })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No columns yet')).toBeInTheDocument()
  })

  it('shows empty state when children is an empty array', () => {
    const row = createRowNode({ children: [] as never })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
  })

  it('flanks the columns with the start/end "+" insert squares when columns exist', () => {
    const row = createRowNode({ columnCount: 1 })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
    // A single column has no internal gutter, so no "between" handle.
    expect(screen.queryByTestId('column-insert-between')).not.toBeInTheDocument()
  })

  it('renders a "between" column-insert handle in each internal gutter', () => {
    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<EditableRowBlock row={row} />)

    // 3 columns → 2 internal gutters → 2 "between" handles, plus the 2 edge squares.
    expect(screen.getAllByTestId('column-insert-between')).toHaveLength(2)
    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
  })

  it('offers a shared column in a row that already has columns', () => {
    const row = createRowNode({ columnCount: 2 })

    renderWithProviders(<EditableRowBlock row={row} />)

    // Two gutters flank the columns and one sits between them; every one of
    // them can place a shared column. Before this change a populated row
    // offered no shared route at all.
    expect(screen.getAllByTestId('column-insert-shared-trigger').length).toBeGreaterThanOrEqual(3)
  })

  describe('status and state attributes', () => {
    it('forwards the node status to the chrome data-status attribute', () => {
      // Per-status application is pinned in the Chrome test file — this only
      // proves the block wires the node's status through.
      const row = createRowNode({ status: 'draft' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'draft')
    })

    it('sets data-collapsed when the row is collapsed in the context', () => {
      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />, {
        collapsedKeys: [row.nodeKey],
      })

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
    })

    it('does not set data-collapsed when expanded', () => {
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
      const row = createRowNode({})

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('layout mode', () => {
    it('uses flex layout when offset strategy is margin', () => {
      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
    })

    it('uses grid layout when offset strategy is grid-placement', () => {
      // Override adapter config for this test
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
    })

    it('sets --grid-columns CSS variable in grid mode', () => {
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })

    it('does not set --grid-columns CSS variable in flex mode', () => {
      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<EditableRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })
  })

  it('renders edit link when editLink is set', () => {
    const row = createRowNode({ editLink: '/admin/pages/edit/show/10' })

    renderWithProviders(<EditableRowBlock row={row} />)

    const link = screen.getByTestId('row-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/10')
    expect(link).toHaveTextContent(row.title)
  })

  it('renders title as plain text when editLink is null', () => {
    const row = createRowNode({ editLink: null })

    renderWithProviders(<EditableRowBlock row={row} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title)
  })

  describe('drag handle label', () => {
    it('names the drag handle with the row title', () => {
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
      // No child columns, so the only CollapseToggle is the row's own.
      const row = createRowNode({ children: null })
      const collapseState = createCollapseStateStub()

      renderWithProviders(<EditableRowBlock row={row} />, { collapseState })

      // `onToggle = useCallback(() => toggle(row.nodeKey), ...)` — a mutant that
      // drops the body must leave `toggle` uncalled on click.
      await userEvent.click(screen.getByTestId('collapse-toggle'))

      expect(collapseState.toggle).toHaveBeenCalledWith(row.nodeKey)
    })

    it('wires the collapse toggle to the columns area it controls', () => {
      const row = createRowNode({ children: null })

      renderWithProviders(<EditableRowBlock row={row} />)

      const controlsId = screen.getByTestId('collapse-toggle').getAttribute('aria-controls')

      expect(controlsId).not.toBeNull()
      expect(screen.getByTestId('row-block-columns-area')).toHaveAttribute('id', controlsId)
    })
  })

  describe('status marks', () => {
    it('renders the indicator with its label when status is modified', () => {
      const row = createRowNode({ status: 'modified' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Modified')
    })

    it('badges a never-published row as draft', () => {
      const row = createRowNode({ status: 'draft' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Draft')
    })

    it('does not render the badge when the row is published', () => {
      const row = createRowNode({ status: 'published' })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
    })
  })

  describe('column count meta', () => {
    it('renders the column count text when the row has columns', () => {
      const row = createRowNode({ columnCount: 3 })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.getByTestId('row-column-count')).toHaveTextContent('3 columns')
    })

    it('does not render the column count meta when children is an empty array', () => {
      const row = createRowNode({ children: [] as never })

      renderWithProviders(<EditableRowBlock row={row} />)

      // `columnCount > 0` is false for an empty array, so no meta. A `>= 0`
      // mutant would (wrongly) render "0 columns" here.
      expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
    })

    it('does not render the column count meta when children is null', () => {
      const row = createRowNode({ children: null })

      renderWithProviders(<EditableRowBlock row={row} />)

      expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
    })
  })
})

describe('ReadonlyRowBlock', () => {
  it('renders child columns', () => {
    const row = createRowNode({ columnCount: 2 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    // ReadonlyRowBlock maps `row.children` to <ReadonlyColumnBlock>; a mutant
    // that turns the map callback into `() => undefined` renders no columns.
    expect(screen.getAllByTestId('column-block')).toHaveLength(2)
  })

  it('sets data-collapsed to empty string when collapsed', () => {
    const row = createRowNode({})

    renderWithProviders(<ReadonlyRowBlock row={row} />, { collapsedKeys: [row.nodeKey] })

    expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
  })

  it('wires the collapse toggle to the columns body it controls', () => {
    const row = createRowNode({ columnCount: 1 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    // Readonly mode has no columns-area wrapper — the id lives on the columns
    // grid itself, so the readonly path needs its own assertion.
    const controlsId = screen.getAllByTestId('collapse-toggle')[0].getAttribute('aria-controls')

    expect(controlsId).not.toBeNull()
    expect(screen.getByTestId('row-block-columns')).toHaveAttribute('id', controlsId)
  })

  it('does not set data-collapsed when expanded', () => {
    const row = createRowNode({})

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-collapsed')
  })

  it('renders the modified indicator with its label when status is modified', () => {
    const row = createRowNode({ status: 'modified' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Modified')
  })

  it('badges a never-published readonly row as draft', () => {
    const row = createRowNode({ status: 'draft' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-status-badge')).toHaveTextContent('Draft')
  })

  it('does not render the badge when the readonly row is published', () => {
    const row = createRowNode({ status: 'published' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-status-badge')).not.toBeInTheDocument()
  })

  it('renders the column count text when the row has columns', () => {
    const row = createRowNode({ columnCount: 2 })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.getByTestId('row-column-count')).toHaveTextContent('2 columns')
  })

  it('does not render the column count meta when children is an empty array', () => {
    const row = createRowNode({ children: [] as never })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-column-count')).not.toBeInTheDocument()
  })

  it('renders title as plain text even when editLink is set', () => {
    const row = createRowNode({ editLink: '/admin/pages/edit/show/10' })

    renderWithProviders(<ReadonlyRowBlock row={row} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title)
  })

  it('does not render the start/end insert squares or the row drag handle', () => {
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
      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<ReadonlyRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })

    it('uses grid layout and sets --grid-columns when offset strategy is grid-placement', () => {
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'
      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<ReadonlyRowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })
  })
})
