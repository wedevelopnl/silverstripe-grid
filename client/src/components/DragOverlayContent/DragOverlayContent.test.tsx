import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { createColumnNode, createRowNode, createSectionNode, createSimpleElement, resetIdCounter } from '@/testing/factories';

import DragOverlayContent from './DragOverlayContent';

describe('DragOverlayContent', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  it('shows "1 row" for a section with one row', () => {
    const section = createSectionNode({ rowCount: 1 });

    render(<DragOverlayContent node={section} type="section" />);

    expect(screen.getByText('1 row')).toBeInTheDocument();
  });

  it('shows "3 rows" for a section with three rows', () => {
    const section = createSectionNode({ rowCount: 3 });

    render(<DragOverlayContent node={section} type="section" />);

    expect(screen.getByText('3 rows')).toBeInTheDocument();
  });

  it('shows "0 rows" for a section with no rows', () => {
    const section = createSectionNode({ children: null });

    render(<DragOverlayContent node={section} type="section" />);

    expect(screen.getByText('0 rows')).toBeInTheDocument();
  });

  it('shows "1 column" for a row with one column', () => {
    const row = createRowNode({ columnCount: 1 });

    render(<DragOverlayContent node={row} type="row" />);

    expect(screen.getByText('1 column')).toBeInTheDocument();
  });

  it('shows "2 columns" for a row with two columns', () => {
    const row = createRowNode({ columnCount: 2 });

    render(<DragOverlayContent node={row} type="row" />);

    expect(screen.getByText('2 columns')).toBeInTheDocument();
  });

  it('shows only the title for a column without child count meta', () => {
    const column = createColumnNode({ title: 'My Column' });

    const { container } = render(<DragOverlayContent node={column} type="column" />);

    expect(screen.getByTestId('drag-overlay-column-title')).toHaveTextContent('My Column');
    expect(container.querySelector('.drag-overlay-content__meta')).toBeNull();
  });

  it('shows only the title for a content element', () => {
    const element = createSimpleElement({ title: 'My Element' });

    render(<DragOverlayContent node={element} type="element" />);

    expect(screen.getByTestId('drag-overlay-element-title')).toHaveTextContent('My Element');
  });

  it('applies type modifier CSS class', () => {
    const section = createSectionNode();

    render(<DragOverlayContent node={section} type="section" />);

    expect(screen.getByTestId('drag-overlay-section')).toHaveClass(
      'drag-overlay-content',
      'drag-overlay-content--section',
    );
  });

  it('icon includes the blockSchema icon class for section', () => {
    const section = createSectionNode({
      blockSchema: {
        typeName: 'Section',
        label: 'Section',
        icon: 'font-icon-block-layout',
        type: 'Section',
        title: 'Section',
        summary: '',
      },
    });

    render(<DragOverlayContent node={section} type="section" />);

    const icon = screen.getByTestId('drag-overlay-section').querySelector('.drag-overlay-content__icon');
    expect(icon).toHaveClass('drag-overlay-content__icon', 'font-icon-block-layout');
  });

  it('icon includes the blockSchema icon class for row', () => {
    const row = createRowNode({
      blockSchema: {
        typeName: 'Row',
        label: 'Row',
        icon: 'font-icon-block-row',
        type: 'Row',
        title: 'Row',
        summary: '',
      },
    });

    render(<DragOverlayContent node={row} type="row" />);

    const icon = screen.getByTestId('drag-overlay-row').querySelector('.drag-overlay-content__icon');
    expect(icon).toHaveClass('drag-overlay-content__icon', 'font-icon-block-row');
  });

  it('data-testid includes the type for each variant', () => {
    const column = createColumnNode({ title: 'Col' });

    render(<DragOverlayContent node={column} type="column" />);

    expect(screen.getByTestId('drag-overlay-column')).toBeInTheDocument();
    expect(screen.getByTestId('drag-overlay-column-title')).toBeInTheDocument();
  });

  it('applies row type modifier CSS class', () => {
    const row = createRowNode();

    render(<DragOverlayContent node={row} type="row" />);

    expect(screen.getByTestId('drag-overlay-row')).toHaveClass(
      'drag-overlay-content',
      'drag-overlay-content--row',
    );
  });

  it('applies element type modifier CSS class', () => {
    const element = createSimpleElement({ title: 'My Element' });

    render(<DragOverlayContent node={element} type="element" />);

    expect(screen.getByTestId('drag-overlay-element')).toHaveClass(
      'drag-overlay-content',
      'drag-overlay-content--element',
    );
  });
});
