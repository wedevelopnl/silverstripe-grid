import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ReadonlyProvider } from '@/hooks/ReadonlyContext'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { createRowNode } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'
import { resetAdapterCache } from '@/utils/gridAdapter'

import RowBlock from './RowBlock'

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

describe('RowBlock', () => {
  it('renders row title', () => {
    mockFetchSuccess({})

    const row = createRowNode({ title: 'Main Row' })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row')
  })

  it('renders child columns', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.getAllByTestId('column-block')).toHaveLength(3)
  })

  it('shows empty state when no children', () => {
    mockFetchSuccess({})

    const row = createRowNode({ children: null })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No columns yet')).toBeInTheDocument()
  })

  it('flanks the columns with the start/end "+" insert squares when columns exist', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 1 })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
    // A single column has no internal gutter, so no "between" handle.
    expect(screen.queryByTestId('column-insert-between')).not.toBeInTheDocument()
  })

  it('renders a "between" column-insert handle in each internal gutter', () => {
    mockFetchSuccess({})

    const row = createRowNode({ columnCount: 3 })

    renderWithProviders(<RowBlock row={row} />)

    // 3 columns → 2 internal gutters → 2 "between" handles, plus the 2 edge squares.
    expect(screen.getAllByTestId('column-insert-between')).toHaveLength(2)
    expect(screen.getByTestId('column-insert-start')).toBeInTheDocument()
    expect(screen.getByTestId('column-insert-end')).toBeInTheDocument()
  })

  it('shows empty state when children is an empty array', () => {
    mockFetchSuccess({})

    const row = createRowNode({ children: [] as never })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
  })

  describe('status and state attributes', () => {
    it('includes draft status attribute', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'draft' })

      renderWithProviders(<RowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'draft')
    })

    it('includes modified status attribute', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'modified' })

      renderWithProviders(<RowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'modified')
    })

    it('includes published status attribute by default', () => {
      mockFetchSuccess({})

      const row = createRowNode({ status: 'published' })

      renderWithProviders(<RowBlock row={row} />)

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-status', 'published')
    })

    it('sets data-collapsed when the row is collapsed in the context', () => {
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<RowBlock row={row} />, {
        collapsedKeys: [row.nodeKey],
      })

      expect(screen.getByTestId('row-block')).toHaveAttribute('data-collapsed', '')
    })

    it('does not set data-collapsed when expanded', () => {
      mockFetchSuccess({})

      const row = createRowNode({})

      renderWithProviders(<RowBlock row={row} />)

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

      renderWithProviders(<RowBlock row={row} />)

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

      renderWithProviders(<RowBlock row={row} />)

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

      renderWithProviders(<RowBlock row={row} />)

      expect(screen.getByTestId('row-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('layout mode', () => {
    it('uses flex layout when offset strategy is margin', () => {
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<RowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
    })

    it('uses grid layout when offset strategy is grid-placement', () => {
      // Override adapter config for this test
      resetAdapterCache()
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<RowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
    })

    it('sets --grid-columns CSS variable in grid mode', () => {
      resetAdapterCache()
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<RowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })

    it('does not set --grid-columns CSS variable in flex mode', () => {
      resetAdapterCache()
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(<RowBlock row={row} />)

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })
  })

  it('renders edit link when editLink is set', () => {
    mockFetchSuccess({})

    const row = createRowNode({ editLink: '/admin/pages/edit/show/10' })

    renderWithProviders(<RowBlock row={row} />)

    const link = screen.getByTestId('row-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/10')
    expect(link).toHaveTextContent(row.title)
  })

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({})

    const row = createRowNode({ editLink: null })

    renderWithProviders(<RowBlock row={row} />)

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title)
  })

  describe('readonly mode layout', () => {
    // Pins the `getOffsetStrategy() === 'margin' ? 'flex' : 'grid'` ternary
    // at RowBlock.tsx:123 and the `layoutMode === 'grid'` guard at :143 —
    // both live on the ReadonlyRowBlock path, not the editable one. The
    // existing edit-path tests don't exercise these lines.

    it('uses flex layout and omits --grid-columns when offset strategy is margin', () => {
      resetAdapterCache()
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <RowBlock row={row} />
        </ReadonlyProvider>,
      )

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'flex')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('')
    })

    it('uses grid layout and sets --grid-columns when offset strategy is grid-placement', () => {
      resetAdapterCache()
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'
      mockFetchSuccess({})

      const row = createRowNode({ columnCount: 1 })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <RowBlock row={row} />
        </ReadonlyProvider>,
      )

      const columnsDiv = screen.getByTestId('row-block-columns')
      expect(columnsDiv).toHaveAttribute('data-layout-mode', 'grid')
      expect((columnsDiv as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12')
    })
  })
})
