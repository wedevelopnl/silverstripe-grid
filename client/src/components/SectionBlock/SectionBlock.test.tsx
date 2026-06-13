import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ReadonlyProvider } from '@/hooks/ReadonlyContext'
import { useDragContext } from '@/hooks/useDragAndDrop'
import { createSectionNode } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { createCollapseStateStub, renderWithProviders } from '@/testing/renderWithProviders'

import SectionBlock from './SectionBlock'

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

describe('SectionBlock', () => {
  it('renders section title', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ title: 'Hero Section' })

    renderWithProviders(<SectionBlock section={section} />)

    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section')
  })

  it('renders child rows', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ rowCount: 2 })

    renderWithProviders(<SectionBlock section={section} />)

    expect(screen.getAllByTestId('row-block')).toHaveLength(2)
  })

  it('shows empty state when no children', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ children: null })

    renderWithProviders(<SectionBlock section={section} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No rows yet')).toBeInTheDocument()
  })

  it('shows append AddChildButton when children exist', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<SectionBlock section={section} />)

    // Section's own append button + child row/column append buttons
    const appendButtons = screen.getAllByTestId('add-child-append')
    expect(appendButtons.length).toBeGreaterThan(0)
    // The section's button says "Add Row"
    expect(screen.getByText('Add Row')).toBeInTheDocument()
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
  })

  it('collapse hides children', async () => {
    const user = userEvent.setup()
    mockFetchSuccess({})

    const section = createSectionNode({ rowCount: 1 })
    const collapseState = createCollapseStateStub()

    renderWithProviders(<SectionBlock section={section} />, { collapseState })

    // Initially expanded — section should not have data-collapsed attribute
    expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-collapsed')

    // There are multiple collapse toggles (section + child rows/columns).
    // The first one belongs to the section header.
    const toggles = screen.getAllByTestId('collapse-toggle')
    await user.click(toggles[0])

    // The toggle callback was called with the section's NodeKey.
    expect(collapseState.toggle).toHaveBeenCalledOnce()
    expect(collapseState.toggle).toHaveBeenCalledWith(section.nodeKey)
  })

  it('applies collapsed class when the section is collapsed in the context', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<SectionBlock section={section} />, {
      collapsedKeys: [section.nodeKey],
    })

    expect(screen.getByTestId('section-block')).toHaveAttribute('data-collapsed', '')
  })

  it('edit link rendered when editLink exists', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<SectionBlock section={section} />)

    const link = screen.getByTestId('section-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
    expect(link).toHaveTextContent(section.title)
  })

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ editLink: null })

    renderWithProviders(<SectionBlock section={section} />)

    expect(screen.queryByTestId('section-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent(section.title)
  })

  it('shows empty state when children is an empty array', () => {
    mockFetchSuccess({})

    const section = createSectionNode({ children: [] as never })

    renderWithProviders(<SectionBlock section={section} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
  })

  describe('status and state attributes', () => {
    it('includes draft status attribute', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'draft' })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-status', 'draft')
    })

    it('includes modified status attribute', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'modified' })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-status', 'modified')
    })

    it('includes published status attribute by default', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'published' })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-status', 'published')
    })

    it('sets data-drop-target when isOver and activeType is section', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section', pendingActive: false })
      mockFetchSuccess({})

      const section = createSectionNode({})

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-drop-target', '')
    })

    it('does not set data-drop-target when isOver but activeType is not section', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row', pendingActive: false })
      mockFetchSuccess({})

      const section = createSectionNode({})

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-drop-target')
    })

    it('does not set data-drop-target when activeType is section but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: false,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section', pendingActive: false })
      mockFetchSuccess({})

      const section = createSectionNode({})

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('modified indicator', () => {
    it('renders an accessible modified indicator when the section is modified', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'modified' })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.getByRole('img', { name: 'Has unpublished changes' })).toBeInTheDocument()
    })

    it('omits the modified indicator when the section is not modified', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'published' })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.queryByRole('img', { name: 'Has unpublished changes' })).not.toBeInTheDocument()
    })
  })

  describe('drag handle', () => {
    it('labels the drag handle with the interpolated section title', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ title: 'Hero Section', children: null })

      renderWithProviders(<SectionBlock section={section} />)

      // With no child rows the only drag handle belongs to the section itself.
      expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Move Hero Section')
    })
  })

  describe('between AddChildButton', () => {
    it('renders a between button in the gap separating two rows', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ rowCount: 2 })

      renderWithProviders(<SectionBlock section={section} />)

      // Two rows produce exactly one gap → one "between" insert button.
      expect(screen.getAllByTestId('add-child-between')).toHaveLength(1)
    })

    it('renders no between button with a single row', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ rowCount: 1 })

      renderWithProviders(<SectionBlock section={section} />)

      expect(screen.queryByTestId('add-child-between')).not.toBeInTheDocument()
    })
  })

  describe('readonly variant', () => {
    it('renders child rows in the readonly tree', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ rowCount: 2 })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <SectionBlock section={section} />
        </ReadonlyProvider>,
      )

      expect(screen.getAllByTestId('row-block')).toHaveLength(2)
    })

    it('marks a collapsed readonly section with an empty data-collapsed attribute', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ rowCount: 1 })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <SectionBlock section={section} />
        </ReadonlyProvider>,
        { collapsedKeys: [section.nodeKey] },
      )

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-collapsed', '')
    })

    it('renders an accessible modified indicator when a readonly section is modified', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'modified' })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <SectionBlock section={section} />
        </ReadonlyProvider>,
      )

      expect(screen.getByRole('img', { name: 'Has unpublished changes' })).toBeInTheDocument()
    })

    it('omits the modified indicator when a readonly section is not modified', () => {
      mockFetchSuccess({})

      const section = createSectionNode({ status: 'published' })

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <SectionBlock section={section} />
        </ReadonlyProvider>,
      )

      expect(screen.queryByRole('img', { name: 'Has unpublished changes' })).not.toBeInTheDocument()
    })
  })
})
