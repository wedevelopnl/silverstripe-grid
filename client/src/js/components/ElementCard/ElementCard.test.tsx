import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createSimpleElement } from '@/testing/factories'
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

  describe('modified indicator', () => {
    it('renders the modified indicator with its label when status is modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'modified' })

      renderWithProviders(<EditableElementCard element={element} />)

      const indicator = screen.getByTestId('element-card-modified-indicator')
      expect(indicator).toBeInTheDocument()
      expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
    })

    it('does not render the modified indicator when status is not modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'published' })

      renderWithProviders(<EditableElementCard element={element} />)

      expect(screen.queryByTestId('element-card-modified-indicator')).toBeNull()
    })
  })

  describe('edit-link click handling', () => {
    it('does not nest the interactive controls inside the edit-link anchor', () => {
      // Conformance: buttons/menus must not be descendants of an <a>. The drag
      // handle and actions are siblings of the anchor, not inside it.
      mockFetchSuccess({})

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })
      renderWithProviders(<EditableElementCard element={element} />)

      const link = screen.getByTestId('element-card-link')
      expect(link.querySelector('button')).toBeNull()
      // But the drag handle button still exists in the card, as a sibling.
      expect(screen.getByTestId('element-card').querySelector('button')).not.toBeNull()
    })

    it('prevents navigation while a drag is in progress', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isDragging: true,
      } as unknown as ReturnType<typeof useSortable>)
      mockFetchSuccess({})

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })
      renderWithProviders(<EditableElementCard element={element} />)

      // The onClick guard is on the anchor now; dispatch there.
      const link = screen.getByTestId('element-card-link')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = link.dispatchEvent(event)

      // dispatchEvent returns false when preventDefault was called
      expect(result).toBe(false)
      expect(event.defaultPrevented).toBe(true)
    })

    it('allows navigation on a normal (non-drag) edit-link click', () => {
      mockFetchSuccess({})

      // A same-document fragment href keeps jsdom from attempting a real
      // cross-document navigation while exercising the un-prevented click path.
      const element = createSimpleElement({
        title: 'Navigate me',
        editLink: '#edit',
      })
      renderWithProviders(<EditableElementCard element={element} />)

      const link = screen.getByTestId('element-card-link')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = link.dispatchEvent(event)

      // Not prevented — anchor navigation would proceed.
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

  it('renders the modified indicator with its label when status is modified', () => {
    const element = createSimpleElement({ status: 'modified' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    const indicator = screen.getByTestId('element-card-modified-indicator')
    expect(indicator).toBeInTheDocument()
    expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
  })

  it('does not render the modified indicator when status is not modified', () => {
    const element = createSimpleElement({ status: 'published' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.queryByTestId('element-card-modified-indicator')).toBeNull()
  })

  it('renders the summary line when a non-empty value is provided', () => {
    const element = createSimpleElement({ summary: 'A short preview of the block' })

    renderWithProviders(<ReadonlyElementCard element={element} />)

    expect(screen.getByTestId('element-card-summary')).toHaveTextContent(
      'A short preview of the block',
    )
  })
})
