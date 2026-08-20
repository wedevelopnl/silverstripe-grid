import { useSortable } from '@dnd-kit/sortable'
import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useDragContext } from '@/hooks/useDragAndDrop'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
} from '@/testing/factories'
import { defaultSortable, resetDndMocks } from '@/testing/mockDndKit'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { createCollapseStateStub, renderWithProviders } from '@/testing/renderWithProviders'

import EditableSectionBlock from './EditableSectionBlock'
import ReadonlySectionBlock from './ReadonlySectionBlock'

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

describe('EditableSectionBlock', () => {
  it('renders section title', () => {
    const section = createSectionNode({ title: 'Hero Section' })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section')
  })

  it('renders child rows', () => {
    const section = createSectionNode({ rowCount: 2 })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.getAllByTestId('row-block')).toHaveLength(2)
  })

  it('shows empty state when no children', () => {
    const section = createSectionNode({ children: null })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
    expect(screen.getByText('No rows yet')).toBeInTheDocument()
  })

  it('shows empty state when children is an empty array', () => {
    const section = createSectionNode({ children: [] as never })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument()
  })

  it('shows append AddChildButton when children exist', () => {
    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<EditableSectionBlock section={section} />)

    // Section's own append button + child row/column append buttons
    const appendButtons = screen.getAllByTestId('add-child-append')
    expect(appendButtons.length).toBeGreaterThan(0)
    // The section's row slots — one above the first row, one after the last.
    expect(screen.getAllByText('Add Row')).toHaveLength(2)
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument()
  })

  it('renders a between button in the gap separating two rows', () => {
    const section = createSectionNode({ rowCount: 2 })

    renderWithProviders(<EditableSectionBlock section={section} />)

    // Two rows produce exactly one gap → one "between" insert button. The slot
    // above the first row is the separate before-first button.
    expect(screen.getAllByTestId('add-child-between')).toHaveLength(1)
    expect(screen.getAllByTestId('add-child-before-first')).toHaveLength(1)
  })

  it('renders no between button with a single row', () => {
    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.queryByTestId('add-child-between')).not.toBeInTheDocument()
    // A lone row still gets a leading slot — prepending must not require a gap.
    expect(screen.getAllByTestId('add-child-before-first')).toHaveLength(1)
  })

  it('edit link rendered when editLink exists', () => {
    const section = createSectionNode({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<EditableSectionBlock section={section} />)

    const link = screen.getByTestId('section-edit-link')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
    expect(link).toHaveTextContent(section.title)
  })

  it('renders title as plain text when editLink is null', () => {
    const section = createSectionNode({ editLink: null })

    renderWithProviders(<EditableSectionBlock section={section} />)

    expect(screen.queryByTestId('section-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent(section.title)
  })

  describe('status and state attributes', () => {
    it('forwards the node status to the chrome data-status attribute', () => {
      // Per-status application is pinned in the Chrome test file — this only
      // proves the block wires the node's status through.
      const section = createSectionNode({ status: 'draft' })

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-status', 'draft')
    })

    it('sets data-drop-target when isOver and activeType is section', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section', pendingActive: false })
      const section = createSectionNode({})

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-drop-target', '')
    })

    it('does not set data-drop-target when isOver but activeType is not section', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row', pendingActive: false })
      const section = createSectionNode({})

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-drop-target')
    })

    it('does not set data-drop-target when activeType is section but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: false,
      } as unknown as ReturnType<typeof useSortable>)
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section', pendingActive: false })
      const section = createSectionNode({})

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.getByTestId('section-block')).not.toHaveAttribute('data-drop-target')
    })
  })

  describe('publish-status marks', () => {
    it('badges the section when the section itself is modified', () => {
      const section = createSectionNode({ status: 'modified' })

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.getByTestId('section-status-badge')).toHaveTextContent('Modified')
      expect(
        within(screen.getByTestId('section-header')).queryByText('Contains unpublished changes'),
      ).not.toBeInTheDocument()
    })

    // The case the roll-up exists for: nothing about the section itself
    // changed, but a block three levels down did.
    it('announces the section descendant when only a nested block is modified', () => {
      const section = createSectionNode({
        status: 'published',
        children: [
          createRowNode({
            children: [
              createColumnNode({ children: [createSimpleElement({ status: 'modified' })] }),
            ],
          }),
        ],
      })

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(
        within(screen.getByTestId('section-header')).getByText('Contains unpublished changes'),
      ).toBeInTheDocument()
      expect(screen.queryByTestId('section-status-badge')).not.toBeInTheDocument()
      expect(screen.getByTestId('section-block')).toHaveAttribute('data-descendant-unpublished')
    })

    // Draft rolls up on the same footing as modified: a block that has never
    // been published is exactly as hidden inside a collapsed section, and
    // publishing is the same remedy for both.
    it('announces the section descendant when only a nested block is draft', () => {
      const section = createSectionNode({
        status: 'published',
        children: [
          createRowNode({
            children: [createColumnNode({ children: [createSimpleElement({ status: 'draft' })] })],
          }),
        ],
      })

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(
        within(screen.getByTestId('section-header')).getByText('Contains unpublished changes'),
      ).toBeInTheDocument()
      expect(screen.queryByTestId('section-status-badge')).not.toBeInTheDocument()
    })

    it('omits both marks when nothing in the section is unpublished', () => {
      const section = createSectionNode({ status: 'published' })

      renderWithProviders(<EditableSectionBlock section={section} />)

      expect(screen.queryByTestId('section-status-badge')).not.toBeInTheDocument()
      expect(
        within(screen.getByTestId('section-header')).queryByText('Contains unpublished changes'),
      ).not.toBeInTheDocument()
    })
  })

  describe('drag handle', () => {
    it('labels the drag handle with the interpolated section title', () => {
      const section = createSectionNode({ title: 'Hero Section', children: null })

      renderWithProviders(<EditableSectionBlock section={section} />)

      // With no child rows the only drag handle belongs to the section itself.
      expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Move Hero Section')
    })
  })

  describe('collapse', () => {
    it('calls toggle with the section node key when the collapse toggle is clicked', async () => {
      const user = userEvent.setup()
      const section = createSectionNode({ rowCount: 1 })
      const collapseState = createCollapseStateStub()

      renderWithProviders(<EditableSectionBlock section={section} />, { collapseState })

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

    it('applies collapsed attribute when the section is collapsed in the context', () => {
      const section = createSectionNode({ rowCount: 1 })

      renderWithProviders(<EditableSectionBlock section={section} />, {
        collapsedKeys: [section.nodeKey],
      })

      expect(screen.getByTestId('section-block')).toHaveAttribute('data-collapsed', '')
    })
  })
})

describe('ReadonlySectionBlock', () => {
  it('renders child rows in the readonly tree', () => {
    const section = createSectionNode({ rowCount: 2 })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.getAllByTestId('row-block')).toHaveLength(2)
  })

  it('renders no rows when children is null', () => {
    const section = createSectionNode({ children: null })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.queryByTestId('row-block')).not.toBeInTheDocument()
  })

  it('marks a collapsed readonly section with an empty data-collapsed attribute', () => {
    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<ReadonlySectionBlock section={section} />, {
      collapsedKeys: [section.nodeKey],
    })

    expect(screen.getByTestId('section-block')).toHaveAttribute('data-collapsed', '')
  })

  it('badges a readonly section that is itself modified', () => {
    const section = createSectionNode({ status: 'modified' })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.getByTestId('section-status-badge')).toHaveTextContent('Modified')
  })

  it('announces the descendant on a readonly section when a nested block is modified', () => {
    const section = createSectionNode({
      status: 'published',
      children: [
        createRowNode({
          children: [createColumnNode({ children: [createSimpleElement({ status: 'modified' })] })],
        }),
      ],
    })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(
      within(screen.getByTestId('section-header')).getByText('Contains unpublished changes'),
    ).toBeInTheDocument()
  })

  it('omits the modified indicator when a readonly section is not modified', () => {
    const section = createSectionNode({ status: 'published' })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.queryByText('Contains unpublished changes')).not.toBeInTheDocument()
  })

  it('renders no drag handle or add-child buttons', () => {
    const section = createSectionNode({ rowCount: 1 })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.queryByTestId('drag-handle')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-child-append')).not.toBeInTheDocument()
    expect(screen.queryByTestId('add-child-before-first')).not.toBeInTheDocument()
  })

  it('renders title as plain text even when editLink is set', () => {
    const section = createSectionNode({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<ReadonlySectionBlock section={section} />)

    expect(screen.queryByTestId('section-edit-link')).not.toBeInTheDocument()
    expect(screen.getByTestId('section-title')).toHaveTextContent(section.title)
  })
})
