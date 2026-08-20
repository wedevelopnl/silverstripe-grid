import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'

import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'

import DragOverlayContent from './DragOverlayContent'

describe('DragOverlayContent', () => {
  beforeEach(() => {
    resetIdCounter()
  })

  it('shows exact "1 row" text for a section with one row', () => {
    const section = createSectionNode({ rowCount: 1 })

    render(<DragOverlayContent node={section} type="section" />)

    const meta = screen.getByTestId('drag-overlay-section-meta')
    expect(meta).toBeInTheDocument()
    expect(meta.textContent).toBe('1 row')
  })

  it('shows exact "3 rows" text for a section with three rows', () => {
    const section = createSectionNode({ rowCount: 3 })

    render(<DragOverlayContent node={section} type="section" />)

    const meta = screen.getByTestId('drag-overlay-section-meta')
    expect(meta.textContent).toBe('3 rows')
  })

  it('shows "0 rows" for a section with no rows', () => {
    const section = createSectionNode({ children: null })

    render(<DragOverlayContent node={section} type="section" />)

    expect(screen.getByText('0 rows')).toBeInTheDocument()
  })

  it('shows exact "1 column" text for a row with one column', () => {
    const row = createRowNode({ columnCount: 1 })

    render(<DragOverlayContent node={row} type="row" />)

    const meta = screen.getByTestId('drag-overlay-row-meta')
    expect(meta.textContent).toBe('1 column')
  })

  it('shows exact "2 columns" text for a row with two columns', () => {
    const row = createRowNode({ columnCount: 2 })

    render(<DragOverlayContent node={row} type="row" />)

    const meta = screen.getByTestId('drag-overlay-row-meta')
    expect(meta.textContent).toBe('2 columns')
  })

  it('shows only the title for a column without child count meta', () => {
    const column = createColumnNode({ title: 'My Column' })

    render(<DragOverlayContent node={column} type="column" />)

    expect(screen.getByTestId('drag-overlay-column-title')).toHaveTextContent('My Column')
    expect(screen.queryByTestId('drag-overlay-column-meta')).toBeNull()
  })

  it('shows only the title for a content element and has no meta', () => {
    const element = createSimpleElement({ title: 'My Element' })

    render(<DragOverlayContent node={element} type="element" />)

    expect(screen.getByTestId('drag-overlay-element-title')).toHaveTextContent('My Element')
    expect(screen.queryByTestId('drag-overlay-element-meta')).toBeNull()
  })

  function schema(typeName: string, icon: string) {
    return { typeName, label: typeName, icon, type: typeName, title: typeName }
  }

  it.each([
    {
      type: 'section' as const,
      icon: 'font-icon-block-layout',
      make: () => createSectionNode({ blockSchema: schema('Section', 'font-icon-block-layout') }),
    },
    {
      type: 'row' as const,
      icon: 'font-icon-block-row',
      make: () => createRowNode({ blockSchema: schema('Row', 'font-icon-block-row') }),
    },
    {
      type: 'column' as const,
      icon: 'font-icon-block-column',
      make: () => createColumnNode({ blockSchema: schema('Column', 'font-icon-block-column') }),
    },
    {
      type: 'element' as const,
      icon: 'font-icon-block-content',
      make: () =>
        createSimpleElement({ blockSchema: schema('Content', 'font-icon-block-content') }),
    },
  ])('icon includes the blockSchema icon class for $type', ({ type, icon, make }) => {
    render(<DragOverlayContent node={make()} type={type} />)

    const iconElement = screen.getByTestId(`drag-overlay-${type}-icon`)
    expect(iconElement).toHaveClass('ssgrid-drag-overlay-icon')
    expect(iconElement).toHaveClass(icon)
  })

  it.each([
    {
      type: 'section' as const,
      title: 'My Section',
      make: () => createSectionNode({ title: 'My Section', rowCount: 1 }),
    },
    {
      type: 'row' as const,
      title: 'My Row',
      make: () => createRowNode({ title: 'My Row', columnCount: 1 }),
    },
  ])('renders the title in the type-scoped title testid for a $type', ({ type, title, make }) => {
    render(<DragOverlayContent node={make()} type={type} />)

    expect(screen.getByTestId(`drag-overlay-${type}-title`)).toHaveTextContent(title)
  })

  it('data-testid includes the type for each variant', () => {
    const column = createColumnNode({ title: 'Col' })

    render(<DragOverlayContent node={column} type="column" />)

    expect(screen.getByTestId('drag-overlay-column')).toBeInTheDocument()
    expect(screen.getByTestId('drag-overlay-column-title')).toBeInTheDocument()
  })
})
