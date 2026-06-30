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

  it('icon includes the blockSchema icon class for section', () => {
    const section = createSectionNode({
      blockSchema: {
        typeName: 'Section',
        label: 'Section',
        icon: 'font-icon-block-layout',
        type: 'Section',
        title: 'Section',
      },
    })

    render(<DragOverlayContent node={section} type="section" />)

    expect(screen.getByTestId('drag-overlay-section-icon')).toHaveClass('font-icon-block-layout')
  })

  it('icon includes the blockSchema icon class for row', () => {
    const row = createRowNode({
      blockSchema: {
        typeName: 'Row',
        label: 'Row',
        icon: 'font-icon-block-row',
        type: 'Row',
        title: 'Row',
      },
    })

    render(<DragOverlayContent node={row} type="row" />)

    expect(screen.getByTestId('drag-overlay-row-icon')).toHaveClass('font-icon-block-row')
  })

  it('icon includes the blockSchema icon class for column', () => {
    const column = createColumnNode({
      blockSchema: {
        typeName: 'Column',
        label: 'Column',
        icon: 'font-icon-block-column',
        type: 'Column',
        title: 'Column',
      },
    })

    render(<DragOverlayContent node={column} type="column" />)

    const icon = screen.getByTestId('drag-overlay-column-icon')
    expect(icon).toHaveClass('ssgrid-drag-overlay__icon')
    expect(icon).toHaveClass('font-icon-block-column')
  })

  it('icon includes the blockSchema icon class for content element', () => {
    const element = createSimpleElement({
      blockSchema: {
        typeName: 'Content',
        label: 'Content',
        icon: 'font-icon-block-content',
        type: 'Content',
        title: 'Content',
      },
    })

    render(<DragOverlayContent node={element} type="element" />)

    const icon = screen.getByTestId('drag-overlay-element-icon')
    expect(icon).toHaveClass('ssgrid-drag-overlay__icon')
    expect(icon).toHaveClass('font-icon-block-content')
  })

  it('renders the title in the type-scoped title testid for a section', () => {
    const section = createSectionNode({ title: 'My Section', rowCount: 1 })

    render(<DragOverlayContent node={section} type="section" />)

    expect(screen.getByTestId('drag-overlay-section-title')).toHaveTextContent('My Section')
  })

  it('renders the title in the type-scoped title testid for a row', () => {
    const row = createRowNode({ title: 'My Row', columnCount: 1 })

    render(<DragOverlayContent node={row} type="row" />)

    expect(screen.getByTestId('drag-overlay-row-title')).toHaveTextContent('My Row')
  })

  it('data-testid includes the type for each variant', () => {
    const column = createColumnNode({ title: 'Col' })

    render(<DragOverlayContent node={column} type="column" />)

    expect(screen.getByTestId('drag-overlay-column')).toBeInTheDocument()
    expect(screen.getByTestId('drag-overlay-column-title')).toBeInTheDocument()
  })
})
