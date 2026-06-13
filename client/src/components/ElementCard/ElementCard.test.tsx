import { useSortable } from '@dnd-kit/sortable'
import { screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ReadonlyProvider } from '@/hooks/ReadonlyContext'
import { createSimpleElement } from '@/testing/factories'
import { mockFetchSuccess } from '@/testing/mockFetch'
import { renderWithProviders } from '@/testing/renderWithProviders'

import ElementCard from './ElementCard'

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

describe('ElementCard', () => {
  it('renders element title', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ title: 'My Content Block' })

    renderWithProviders(<ElementCard element={element} />)

    expect(screen.getByTestId('element-card-title')).toHaveTextContent('My Content Block')
  })

  it('status attribute applied correctly', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ status: 'draft' })

    renderWithProviders(<ElementCard element={element} />)

    expect(screen.getByTestId('element-card')).toHaveAttribute('data-status', 'draft')
  })

  it('clickable state applied when editLink exists', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<ElementCard element={element} />)

    expect(screen.getByTestId('element-card')).toHaveAttribute('data-state', 'clickable')
  })

  it('no clickable state when editLink is null', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementCard element={element} />)

    expect(screen.getByTestId('element-card')).not.toHaveAttribute('data-state', 'clickable')
  })

  it('renders an anchor with an href so middle-click opens in a new tab', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<ElementCard element={element} />)

    const link = screen.getByRole('link')
    expect(link.tagName).toBe('A')
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5')
  })

  it('renders as non-interactive when editLink is null', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: null })

    renderWithProviders(<ElementCard element={element} />)

    expect(screen.queryByRole('link')).toBeNull()
  })

  it('clickable state is set when editLink is provided', () => {
    mockFetchSuccess({})

    const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })

    renderWithProviders(<ElementCard element={element} />)

    const card = screen.getByTestId('element-card')
    expect(card).toHaveAttribute('data-state', 'clickable')
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

    renderWithProviders(<ElementCard element={element} />)

    const icon = screen.getByTestId('element-card-icon')
    expect(icon).toHaveClass('font-icon-block-content')
  })

  describe('summary', () => {
    it('renders the summary line when a non-empty value is provided', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ summary: 'A short preview of the block' })

      renderWithProviders(<ElementCard element={element} />)

      expect(screen.getByTestId('element-card-summary')).toHaveTextContent(
        'A short preview of the block',
      )
    })

    it('does not render the summary line when the field is absent', () => {
      mockFetchSuccess({})

      const element = createSimpleElement()

      renderWithProviders(<ElementCard element={element} />)

      expect(screen.queryByTestId('element-card-summary')).toBeNull()
    })

    it('does not render the summary line when the value is an empty string', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ summary: '' })

      renderWithProviders(<ElementCard element={element} />)

      expect(screen.queryByTestId('element-card-summary')).toBeNull()
    })
  })

  describe('anchor click handling', () => {
    // Pins the handleAnchorClick guards at ElementCard.tsx:68-82 against
    // ConditionalExpression / LogicalOperator / EqualityOperator / BlockStatement
    // mutations. Each branch (isDragging short-circuit, interactive-descendant
    // detection, plain-text click) is asserted independently.

    it('prevents navigation while a drag is in progress', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isDragging: true,
      } as unknown as ReturnType<typeof useSortable>)
      mockFetchSuccess({})

      const element = createSimpleElement({ editLink: '/admin/pages/edit/show/5' })
      renderWithProviders(<ElementCard element={element} />)

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
      renderWithProviders(<ElementCard element={element} />)

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

      const element = createSimpleElement({
        title: 'Navigate me',
        editLink: '/admin/pages/edit/show/5',
      })
      renderWithProviders(<ElementCard element={element} />)

      // Title is a plain <h4> — no interactive ancestor inside the card.
      const title = screen.getByTestId('element-card-title')
      const event = new MouseEvent('click', { bubbles: true, cancelable: true })
      const result = title.dispatchEvent(event)

      // Not prevented — anchor navigation would proceed.
      expect(result).toBe(true)
      expect(event.defaultPrevented).toBe(false)
    })
  })

  describe('drag handle label', () => {
    // Pins the MOVE_LABEL fallback string and the interpolation params object
    // at ElementCard.tsx:47-49 — both surface as the drag handle's aria-label.

    it('labels the drag handle with the interpolated element title', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ title: 'Hero Banner' })

      renderWithProviders(<ElementCard element={element} />)

      // Fallback "Move {title}" with params { title } => "Move Hero Banner".
      // Killing the fallback StringLiteral ("" => empty label) and the params
      // ObjectLiteral ({} => no substitution, label stays "Move {title}").
      expect(screen.getByTestId('drag-handle')).toHaveAttribute('aria-label', 'Move Hero Banner')
    })
  })

  describe('modified indicator', () => {
    // Pins the `status === 'modified'` conditional at ElementCard.tsx:59 (editable)
    // and :143 (readonly): the unpublished-changes dot must appear only for the
    // 'modified' status, and its aria-label fallback (MODIFIED_LABEL) is asserted.

    it('renders the modified indicator with its label when status is modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'modified' })

      renderWithProviders(<ElementCard element={element} />)

      const indicator = screen.getByTestId('element-card-modified-indicator')
      expect(indicator).toBeInTheDocument()
      expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
    })

    it('does not render the modified indicator when status is not modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'published' })

      renderWithProviders(<ElementCard element={element} />)

      expect(screen.queryByTestId('element-card-modified-indicator')).toBeNull()
    })
  })

  describe('readonly variant', () => {
    // Pins the readonly branch (ElementCard.tsx:129-159): icon class template
    // (:136), modified-indicator conditional (:143) and its label (:147).

    function renderReadonly(element: ReturnType<typeof createSimpleElement>) {
      return renderWithProviders(
        <ReadonlyProvider value={true}>
          <ElementCard element={element} />
        </ReadonlyProvider>,
      )
    }

    it('renders the block icon class', () => {
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

      renderReadonly(element)

      expect(screen.getByTestId('element-card-icon')).toHaveClass('font-icon-block-content')
    })

    it('renders the modified indicator with its label when status is modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'modified' })

      renderReadonly(element)

      const indicator = screen.getByTestId('element-card-modified-indicator')
      expect(indicator).toBeInTheDocument()
      expect(indicator).toHaveAttribute('aria-label', 'Has unpublished changes')
    })

    it('does not render the modified indicator when status is not modified', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ status: 'published' })

      renderReadonly(element)

      expect(screen.queryByTestId('element-card-modified-indicator')).toBeNull()
    })

    it('renders the title and no drag handle', () => {
      mockFetchSuccess({})

      const element = createSimpleElement({ title: 'Readonly Block' })

      renderReadonly(element)

      expect(screen.getByTestId('element-card-title')).toHaveTextContent('Readonly Block')
      expect(screen.queryByTestId('drag-handle')).toBeNull()
    })
  })
})
