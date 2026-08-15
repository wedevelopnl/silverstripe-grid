import { useSortable } from '@dnd-kit/sortable'
import { act, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSimpleElement } from '@/testing/factories'
import { mockResizeObserver } from '@/testing/mockResizeObserver'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import EditableElementCard from './EditableElementCard'
import ReadonlyElementCard from './ReadonlyElementCard'

const defaultSortable = {
  attributes: {},
  listeners: {},
  setNodeRef: vi.fn(),
  transform: null,
  transition: undefined,
  isDragging: false,
}

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: vi.fn(() => ({ ...defaultSortable })),
}))

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<
    typeof useSortable
  >)
})

describe('EditableElementCard', () => {
  describe('when the column is too narrow for the icon row', () => {
    // The header sits in a column the author sizes freely. Below ~300px the
    // six-icon row crushed the title to zero width and spilled past the card
    // edge, so the actions fold into the overflow menu instead.
    function renderAtWidth(width: number) {
      mockFetchSuccess({})
      const observer = mockResizeObserver()
      const element = createSimpleElement({ title: 'My Content Block' })
      const view = renderWithProviders(<EditableElementCard element={element} />)
      act(() => observer.resize(width))
      return { ...view, restore: observer.restore }
    }

    it('shows the icon row while the header has room', () => {
      const { restore } = renderAtWidth(600)

      expect(screen.getByTestId('element-toolbar')).toBeInTheDocument()
      expect(screen.getByTestId('element-action-archive')).toBeInTheDocument()
      restore()
    })

    it('folds the icon row away once the header is cramped', () => {
      const { restore } = renderAtWidth(200)

      expect(screen.queryByTestId('element-toolbar')).not.toBeInTheDocument()
      expect(screen.queryByTestId('element-action-archive')).not.toBeInTheDocument()
      expect(screen.getByTestId('actions-menu-trigger')).toBeInTheDocument()
      restore()
    })

    it('keeps every action reachable from the menu when folded', async () => {
      const user = userEvent.setup()
      const { restore } = renderAtWidth(200)

      await user.click(screen.getByTestId('actions-menu-trigger'))

      // Nothing is dropped on the way into the menu — same set, new presentation.
      const items = screen.getAllByRole('menuitem').map((item) => item.textContent)
      expect(items).toEqual([
        'View history',
        'Duplicate',
        'Open in a new tab',
        'Edit',
        'Archive',
        'Duplicate to…',
      ])
      restore()
    })

    it('restores the icon row when the column is widened again', () => {
      mockFetchSuccess({})
      const observer = mockResizeObserver()
      const element = createSimpleElement({ title: 'My Content Block' })
      renderWithProviders(<EditableElementCard element={element} />)

      act(() => observer.resize(200))
      expect(screen.queryByTestId('element-toolbar')).not.toBeInTheDocument()

      act(() => observer.resize(600))
      expect(screen.getByTestId('element-toolbar')).toBeInTheDocument()
      observer.restore()
    })
  })

  it('renders element title', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ title: 'My Content Block' })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('My Content Block')
  })

  it('status attribute applied correctly', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ status: 'draft' })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.getByTestId('element-card')).toHaveAttribute('data-status', 'draft')
  })

  it('renders an anchor with the editLink href when editLink is set', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<EditableElementCard element={element} />)

    const link = screen.getByRole('link')
    expect(link.tagName).toBe('A')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
  })

  it('renders a div with no link when editLink is null', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: null })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByTestId('element-card').tagName).toBe('DIV')
  })

  it('sets the clickable state when editLink is provided', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.getByTestId('element-card')).toHaveAttribute('data-state', 'clickable')
  })

  it('does not set the clickable state when editLink is null', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: null })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.getByTestId('element-card')).not.toHaveAttribute('data-state', 'clickable')
  })

  it('renders the icon with the blockSchema icon class', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: 'Content',
      },
    })

    renderWithProviders(<EditableElementCard element={element} />)

    expect(screen.getByTestId('element-card-icon')).toHaveClass('font-icon-block-content')
  })

  describe('summary', () => {
    it('renders the summary line when a non-empty value is provided', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ summary: 'A short preview of the block' })

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.getByTestId('element-card-summary')).toHaveTextContent(
        'A short preview of the block',
      )
    })

    it('does not render the summary line when the field is absent', () => {
      mockFetchSuccess({})

      const element = createSimpleElement()

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.queryByTestId('element-card-summary')).toBeNull()
    })

    it('does not render the summary line when the value is an empty string', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ summary: '' })

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.queryByTestId('element-card-summary')).toBeNull()
    })
  })

  describe('drag handle', () => {
    it('renders the drag handle with the interpolated element title label', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ title: 'Hero Banner' })

      renderWithProviders(<EditableElementCard element={element} />)

      // Fallback "Move {title}" with params { title } => "Move Hero Banner".
      expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Move Hero Banner')
    })
  })

  describe('modified badge', () => {
    it('renders the modified badge when status is modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'modified' })

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.getByTestId('element-card-modified-badge')).toHaveTextContent('Modified')
    })

    it('does not render the modified badge when status is not modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'published' })

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.queryByTestId('element-card-modified-badge')).toBeNull()
    })
  })

  describe('anchor click handling', () => {
    // Pins the handleAnchorClick guards (drag-in-progress short-circuit,
    // interactive-descendant detection, plain-text click) moved verbatim from
    // the old dispatcher. Each branch is asserted independently.

    it('prevents navigation while a drag is in progress', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isDragging: true,
      } as unknown as ReturnType<typeof useSortable>)
      mockFetchSuccess({})

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })
      renderWithProviders(<EditableElementCard element={element} />)

      const card = screen.getByTestId('element-card')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = card.dispatchEvent(event)

      // dispatchEvent returns false when preventDefault was called
      expect(result).toBe(false)
      expect(event.defaultPrevented).toBe(true)
    })

    it('prevents navigation when a click originates inside an interactive descendant', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })
      renderWithProviders(<EditableElementCard element={element} />)

      // The anchor contains a drag-handle <button> (rendered by DragHandle).
      const dragHandle = screen.getByTestId('element-card').querySelector('button')
      expect(dragHandle).not.toBeNull()

      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = dragHandle!.dispatchEvent(event)

      expect(result).toBe(false)
      expect(event.defaultPrevented).toBe(true)
    })

    it('allows navigation when the click target has no interactive ancestor inside the card', () => {
      mockFetchSuccess({})

      // A same-document fragment href keeps jsdom from attempting a real
      // cross-document navigation (which logs "Not implemented: navigation")
      // while still exercising the un-prevented click path this test asserts.
      const element = createSimpleElement({
        title: 'Navigate me',
        editLink: '#edit',
      })
      renderWithProviders(<EditableElementCard element={element} />)

      // Title is a plain <h4> — no interactive ancestor inside the card.
      const title = screen.getByTestId('element-card-title')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = title.dispatchEvent(event)

      // Not prevented — anchor navigation would proceed.
      expect(result).toBe(true)
      expect(event.defaultPrevented).toBe(false)
    })

    it('allows navigation when the only interactive ancestor lies outside the card', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({
        title: 'Navigate me',
        editLink: '#edit',
      })

      // Wrap the card in an interactive ancestor. `closest()` climbs past the
      // card's own <a> (not in the selector) and matches this outer element, so
      // `interactive` is non-null — but `currentTarget` (the card anchor) does
      // NOT contain it. The guard `interactive !== null && currentTarget.contains(...)`
      // needs BOTH the non-null match AND containment, so navigation is allowed.
      // Mutating `&&` to `||` would prevent navigation on the non-null match
      // alone; the existing "no interactive ancestor" test (interactive === null)
      // cannot distinguish this because `null || contains(null)` is also false.
      renderWithProviders(
        <div role="dialog" aria-label="outer">
          <EditableElementCard element={element} />
        </div>,
      )

      const title = screen.getByTestId('element-card-title')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = title.dispatchEvent(event)

      expect(result).toBe(true)
      expect(event.defaultPrevented).toBe(false)
    })
  })
})

describe('ReadonlyElementCard', () => {
  it('renders element title', () => {
    const element = createSimpleElement({ title: 'Readonly Block' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('Readonly Block')
  })

  it('renders the icon with the blockSchema icon class', () => {
    const element = createSimpleElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: 'Content',
      },
    })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.getByTestId('element-card-icon')).toHaveClass('font-icon-block-content')
  })

  it('renders no drag handle', () => {
    const element = createSimpleElement()

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.queryByTestId('drag-handle')).toBeNull()
  })

  it('renders no link even when editLink is set (always a div)', () => {
    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.queryByRole('link')).toBeNull()
    expect(screen.getByTestId('element-card').tagName).toBe('DIV')
  })

  it('renders the modified badge when status is modified', () => {
    const element = createSimpleElement({ status: 'modified' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.getByTestId('element-card-modified-badge')).toHaveTextContent('Modified')
  })

  it('does not render the modified badge when status is not modified', () => {
    const element = createSimpleElement({ status: 'published' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.queryByTestId('element-card-modified-badge')).toBeNull()
  })

  it('renders the summary line when a non-empty value is provided', () => {
    const element = createSimpleElement({ summary: 'A short preview of the block' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.getByTestId('element-card-summary')).toHaveTextContent(
      'A short preview of the block',
    )
  })
})
