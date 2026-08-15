import { useSortable } from '@dnd-kit/sortable'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { createColumnNode, createSimpleElement } from '@/testing/factories'
import { getFetchCalls, mockFetchSuccess } from '@/testing/mockFetch'
import { createCollapseStateStub, renderWithProviders } from '@/testing/renderWithProviders'

import EditableColumnBlock from './EditableColumnBlock'
import ReadonlyColumnBlock from './ReadonlyColumnBlock'

// jsdom doesn't support native dialog showModal/close
beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })
})

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

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@dnd-kit/core')>()
  return {
    ...actual,
    DndContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  }
})

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: vi.fn(() => ({ activeType: null, pendingActive: false })),
}))

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<
    typeof useSortable
  >)
  vi.mocked(useDragContext).mockReturnValue({ activeType: null, pendingActive: false })
})

describe('EditableColumnBlock', () => {
  it('renders column children (element cards)', () => {
    mockFetchSuccess({})

    const children = [
      createSimpleElement({ id: 101, title: 'Content A' }),
      createSimpleElement({ id: 102, title: 'Content B' }),
    ]
    const column = createColumnNode({ children, childCount: 0 })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.getAllByTestId('element-card')).toHaveLength(2)
    expect(screen.getByText('Content A')).toBeInTheDocument()
    expect(screen.getByText('Content B')).toBeInTheDocument()
  })

  it('shows empty state when no children and no allowed types', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ children: null, childCount: 0, allowedTypes: null })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.getByText('No content blocks')).toBeInTheDocument()
  })

  it('does not show empty state when no children but allowedTypes exist', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      children: null,
      childCount: 0,
      allowedTypes: {
        'App\\Model\\ContentBlock': { label: 'Content Block', icon: '', description: '' },
      },
    })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.queryByText('No content blocks')).not.toBeInTheDocument()
  })

  it('does not show "Add content" button when allowedTypes is null', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ allowedTypes: null })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.queryByTestId('add-content-button')).not.toBeInTheDocument()
  })

  it('does not show "Add content" button when allowedTypes is empty object', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ allowedTypes: {} })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.queryByTestId('add-content-button')).not.toBeInTheDocument()
  })

  describe('status and state attributes', () => {
    it('includes draft status attribute', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ status: 'draft' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-status', 'draft')
    })

    it('includes modified status attribute', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ status: 'modified' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-status', 'modified')
    })

    it('includes published status attribute by default', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ status: 'published' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-status', 'published')
    })

    it('sets data-hidden when column is not visible', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-hidden', '')
    })

    it('does not set data-hidden when column is visible', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-hidden')
    })

    it('sets data-collapsed when the column is collapsed in the context', () => {
      mockFetchSuccess({})

      const column = createColumnNode({})

      renderWithProviders(<EditableColumnBlock column={column} />, {
        collapsedKeys: [column.nodeKey],
      })

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-collapsed', '')
    })

    it('sets data-drop-target when isOver and activeType is column', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'column', pendingActive: false })
      mockFetchSuccess({})

      const column = createColumnNode({})

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).toHaveAttribute('data-drop-target', '')
    })

    it('does not set data-drop-target when isOver but activeType is not column', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row', pendingActive: false })
      mockFetchSuccess({})

      const column = createColumnNode({})

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-drop-target')
    })

    it('does not set data-drop-target when activeType is column but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: false,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'column', pendingActive: false })
      mockFetchSuccess({})

      const column = createColumnNode({})

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('width picker', () => {
    it('shows current width label', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-badge')).toHaveTextContent('6 columns')
    })

    it('shows "hidden" label when column is not visible', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-badge')).toHaveTextContent('hidden')
    })

    it('width selection calls updateGridSettings with new width and visible=true', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        id: 50,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />, { viewport: 'md' })

      await user.click(screen.getByTestId('column-badge'))

      const options = screen.getAllByRole('option')
      const option = options.find((opt) => opt.textContent === '8 columns')
      expect(option).toBeDefined()
      await user.click(option!)

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
      })

      const [url, init] = getFetchCalls()[0]
      const body = JSON.parse(init!.body as string)

      expect(url).toContain('updateGridSettings')
      expect(body).toMatchObject({
        element: { type: 'column', id: 50 },
        viewport: 'md',
        width: 8,
        visible: true,
        offset: 0,
      })
    })

    it('selecting "hidden" calls updateGridSettings with visible=false', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        id: 51,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />, { viewport: 'md' })

      await user.click(screen.getByTestId('column-badge'))

      const options = screen.getAllByRole('option')
      const hiddenOption = options.find((opt) => opt.textContent === 'hidden')
      expect(hiddenOption).toBeDefined()
      await user.click(hiddenOption!)

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
      })

      const [, init] = getFetchCalls()[0]
      const body = JSON.parse(init!.body as string)

      expect(body).toMatchObject({
        element: { type: 'column', id: 51 },
        viewport: 'md',
        visible: false,
      })
    })

    it('clamps offset when selecting a width that makes current offset too large', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        id: 52,
        gridSettings: { default: { width: 4, offset: 7, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />, { viewport: 'md' })

      await user.click(screen.getByTestId('column-badge'))

      // Select width 10 — max offset is 12-10=2, but current offset is 7
      const options = screen.getAllByRole('option')
      const option = options.find((opt) => opt.textContent === '10 columns')
      expect(option).toBeDefined()
      await user.click(option!)

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
      })

      const [, init] = getFetchCalls()[0]
      const body = JSON.parse(init!.body as string)

      expect(body).toMatchObject({
        element: { type: 'column', id: 52 },
        width: 10,
        offset: 2,
        visible: true,
      })
    })

    it('disables the width picker when a drag is active', () => {
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'column', pendingActive: false })
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-badge')).toBeDisabled()
    })
  })

  describe('offset picker', () => {
    it('shows "none" label when offset is 0', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-offset-badge')).toHaveTextContent('0 offset')
    })

    it('labels a non-zero offset with its value', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 3, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-offset-badge')).toHaveTextContent('3 offset')
    })

    it('disabled when width equals column count', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-offset-badge')).toBeDisabled()
    })

    it('disabled when column is not visible', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-offset-badge')).toBeDisabled()
    })

    it('enabled when width < column count and visible', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('column-offset-badge')).not.toBeDisabled()
    })

    it('offset selection calls updateGridSettings with offset value', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        id: 53,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />, { viewport: 'md' })

      await user.click(screen.getByTestId('column-offset-badge'))

      const options = screen.getAllByRole('option')
      const option = options.find((opt) => opt.textContent === '3 offset')
      expect(option).toBeDefined()
      await user.click(option!)

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
      })

      const [, init] = getFetchCalls()[0]
      const body = JSON.parse(init!.body as string)

      expect(body).toMatchObject({
        element: { type: 'column', id: 53 },
        viewport: 'md',
        offset: 3,
      })
    })
  })

  describe('column style (margin strategy)', () => {
    it('sets --col-width CSS variable based on width/columnCount', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const outerDiv = screen.getByTestId('column-block-outer')
      expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%')
    })

    it('sets --col-offset CSS variable when offset > 0', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 3, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const outerDiv = screen.getByTestId('column-block-outer')
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('25%')
    })

    it('does not set --col-offset when offset is 0', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const outerDiv = screen.getByTestId('column-block-outer')
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('')
    })
  })

  describe('column style (grid-placement strategy)', () => {
    it('sets --col-span and --col-start CSS variables', () => {
      // Override the adapter config to use grid-placement
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'

      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 4, offset: 2, visible: true }, overrides: {} },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const outerDiv = screen.getByTestId('column-block-outer')
      expect(outerDiv.style.getPropertyValue('--col-span')).toBe('4')
      // offset + 1 = 3 for grid-column-start
      expect(outerDiv.style.getPropertyValue('--col-start')).toBe('3')
    })
  })

  describe('element type picker', () => {
    it('shows "Add content" button when allowedTypes exist', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        children: null,
        childCount: 0,
        allowedTypes: {
          'App\\Model\\ContentBlock': { label: 'Content Block', icon: '', description: '' },
        },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.getByTestId('add-content-button')).toHaveTextContent('+ Add content')
    })

    it('opens type picker on "Add content" click and calls createContentElement on select', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        id: 60,
        children: null,
        childCount: 0,
        allowedTypes: {
          'App\\Model\\TextBlock': {
            label: 'Text Block',
            icon: 'font-icon-text',
            description: 'A text block',
          },
        },
      })

      renderWithProviders(<EditableColumnBlock column={column} />, { viewport: 'md' })

      // Open the type picker (component is React.lazy, so await its mount)
      await user.click(screen.getByTestId('add-content-button'))
      await screen.findByTestId('element-type-picker')

      // Click the tile
      await user.click(screen.getByTestId('element-type-tile'))

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled()
      })

      const [url, init] = getFetchCalls()[0]
      const body = JSON.parse(init!.body as string)

      expect(url).toContain('/api/create')
      expect(body).toMatchObject({
        className: 'App\\Model\\TextBlock',
        parent: { type: 'column', id: 60 },
      })
    })

    it('does not render the type picker until "Add content" is clicked', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        children: null,
        childCount: 0,
        allowedTypes: {
          'App\\Model\\TextBlock': {
            label: 'Text Block',
            icon: 'font-icon-text',
            description: 'A text block',
          },
        },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.queryByTestId('element-type-picker')).not.toBeInTheDocument()
      expect(screen.getByTestId('add-content-button')).toBeInTheDocument()
    })

    it('closes the element type picker when close handler is invoked', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({
        children: null,
        childCount: 0,
        allowedTypes: {
          'App\\Model\\TextBlock': {
            label: 'Text Block',
            icon: 'font-icon-text',
            description: 'A text block',
          },
        },
      })

      renderWithProviders(<EditableColumnBlock column={column} />)

      // Open the picker (component is React.lazy, so await its mount)
      await user.click(screen.getByTestId('add-content-button'))
      await screen.findByTestId('element-type-picker')

      // Close it via the close button — the picker is mounted only while open,
      // so closing unmounts it.
      await user.click(screen.getByTestId('element-type-picker-close'))

      await waitFor(() => {
        expect(screen.queryByTestId('element-type-picker')).not.toBeInTheDocument()
      })
    })
  })

  it('shows EmptyState when children is empty array and no allowedTypes', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      children: [] as never,
      childCount: 0,
      allowedTypes: null,
    })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.getByText('No content blocks')).toBeInTheDocument()
  })

  it('renders edit link when editLink is set', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ editLink: '/admin/pages/edit/show/42' })

    renderWithProviders(<EditableColumnBlock column={column} />)

    const link = screen.getByTestId('column-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/42')
    expect(link).toHaveTextContent(column.title)
  })

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ editLink: null })

    renderWithProviders(<EditableColumnBlock column={column} />)

    expect(screen.queryByTestId('column-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('column-title')).toHaveTextContent(column.title)
  })

  describe('collapse toggle', () => {
    it('toggles this column when the collapse control is clicked', async () => {
      const user = userEvent.setup()
      mockFetchSuccess({})

      const column = createColumnNode({})
      const collapseState = createCollapseStateStub()

      renderWithProviders(<EditableColumnBlock column={column} />, { collapseState })

      await user.click(screen.getByTestId('collapse-toggle'))

      expect(collapseState.toggle).toHaveBeenCalledWith(column.nodeKey)
    })
  })

  describe('modified indicator', () => {
    it('renders the indicator with its accessible label when status is modified', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ status: 'modified' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const indicator = screen.getByTestId('column-modified-indicator')
      expect(indicator).toBeInTheDocument()
      expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
    })

    it('does not render the indicator when status is not modified', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ status: 'published' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      expect(screen.queryByTestId('column-modified-indicator')).not.toBeInTheDocument()
    })
  })

  describe('drag handle label', () => {
    it('labels the drag handle with the column title', () => {
      mockFetchSuccess({})

      const column = createColumnNode({ title: 'Hero column' })

      renderWithProviders(<EditableColumnBlock column={column} />)

      const header = screen.getByTestId('column-header')
      expect(within(header).getByTestId('drag-handle')).toHaveAttribute(
        'aria-label',
        'Move Hero column',
      )
    })
  })

  describe('between-column insert handle', () => {
    it('passes an offset-aware gutter shift to the handle when the column has a margin offset', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 2, offset: 1, visible: true }, overrides: {} },
      })

      renderWithProviders(
        <EditableColumnBlock column={column} insertBefore={{ rowId: 9, afterColumnId: 3 }} />,
      )

      // (offset / width) * 50 → (1 / 2) * 50 = 25
      expect(
        screen.getByTestId('column-insert-between').style.getPropertyValue('--ssgrid-insert-shift'),
      ).toBe('25%')
    })

    it('omits the gutter shift when the column has no offset', () => {
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      })

      renderWithProviders(
        <EditableColumnBlock column={column} insertBefore={{ rowId: 9, afterColumnId: 3 }} />,
      )

      expect(
        screen.getByTestId('column-insert-between').style.getPropertyValue('--ssgrid-insert-shift'),
      ).toBe('')
    })

    it('omits the gutter shift under the grid-placement strategy even with an offset', () => {
      // Gutter shift is a margin-strategy concern only. Under grid-placement
      // the offset is expressed via grid-column-start, so no handle nudge is
      // applied regardless of the column's offset.
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'
      mockFetchSuccess({})

      const column = createColumnNode({
        gridSettings: { default: { width: 2, offset: 1, visible: true }, overrides: {} },
      })

      renderWithProviders(
        <EditableColumnBlock column={column} insertBefore={{ rowId: 9, afterColumnId: 3 }} />,
      )

      expect(
        screen.getByTestId('column-insert-between').style.getPropertyValue('--ssgrid-insert-shift'),
      ).toBe('')
    })
  })
})

describe('ReadonlyColumnBlock', () => {
  // Pin the `children.length > 0 ? ... : <EmptyState ...>` ternary against
  // EqualityOperator (`>= 0` / `<= 0`) and ConditionalExpression mutations.
  // Readonly is the isolated render path (no sortable, no mutations, no
  // picker) where the ternary survives.

  it('renders an ElementCard for each child and no empty-state', () => {
    mockFetchSuccess({})

    const children = [
      createSimpleElement({ id: 201, title: 'Readonly A' }),
      createSimpleElement({ id: 202, title: 'Readonly B' }),
      createSimpleElement({ id: 203, title: 'Readonly C' }),
    ]
    const column = createColumnNode({ children, childCount: 0 })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getAllByTestId('element-card')).toHaveLength(3)
    expect(screen.getByText('Readonly A')).toBeInTheDocument()
    expect(screen.getByText('Readonly B')).toBeInTheDocument()
    expect(screen.getByText('Readonly C')).toBeInTheDocument()
    expect(screen.queryByText('No content blocks')).not.toBeInTheDocument()
  })

  it('renders the empty-state message and no ElementCards when children are empty', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ children: null, childCount: 0 })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByText('No content blocks')).toBeInTheDocument()
    expect(screen.queryAllByTestId('element-card')).toHaveLength(0)
  })

  it('sets data-collapsed (empty value) when collapsed', () => {
    mockFetchSuccess({})

    const column = createColumnNode({})

    renderWithProviders(<ReadonlyColumnBlock column={column} />, {
      collapsedKeys: [column.nodeKey],
    })

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-collapsed', '')
  })

  it('does not set data-collapsed when expanded', () => {
    mockFetchSuccess({})

    const column = createColumnNode({})

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-collapsed')
  })

  it('sets data-hidden (empty value) when the column is not visible', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
    })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByTestId('column-block')).toHaveAttribute('data-hidden', '')
  })

  it('does not set data-hidden when the column is visible', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByTestId('column-block')).not.toHaveAttribute('data-hidden')
  })

  it('renders the modified indicator with its accessible label when status is modified', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ status: 'modified' })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    const indicator = screen.getByTestId('column-modified-indicator')
    expect(indicator).toBeInTheDocument()
    expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
  })

  it('does not render the modified indicator when status is not modified', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ status: 'published' })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.queryByTestId('column-modified-indicator')).not.toBeInTheDocument()
  })

  it('renders no drag handle, badges or add-content button', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      allowedTypes: {
        'App\\Model\\TextBlock': { label: 'Text Block', icon: 'font-icon-text', description: '' },
      },
    })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.queryByTestId('drag-handle')).not.toBeInTheDocument()
    expect(screen.queryByTestId('column-badge')).not.toBeInTheDocument()
    expect(screen.queryByTestId('column-offset-badge')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-content-button')).not.toBeInTheDocument()
  })

  it('renders the title as plain text even when editLink is set', () => {
    mockFetchSuccess({})

    const column = createColumnNode({ editLink: '/admin/pages/edit/show/42' })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.queryByTestId('column-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('column-title')).toHaveTextContent(column.title)
  })

  it('applies the --col-width CSS variable via buildColumnStyle (margin strategy)', () => {
    mockFetchSuccess({})

    const column = createColumnNode({
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByTestId('column-block-outer').style.getPropertyValue('--col-width')).toBe(
      '50%',
    )
  })

  it('applies the --col-span CSS variable via buildColumnStyle (grid-placement strategy)', () => {
    window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement'
    mockFetchSuccess({})

    const column = createColumnNode({
      gridSettings: { default: { width: 4, offset: 0, visible: true }, overrides: {} },
    })

    renderWithProviders(<ReadonlyColumnBlock column={column} />)

    expect(screen.getByTestId('column-block-outer').style.getPropertyValue('--col-span')).toBe('4')
  })
})
