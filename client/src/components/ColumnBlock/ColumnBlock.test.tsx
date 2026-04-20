import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { resetAdapterCache } from '@/utils/gridAdapter';

import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import { createColumnNode, createSimpleElement } from '@/testing/factories';
import { renderWithProviders } from '@/testing/renderWithProviders';
import { ReadonlyProvider } from '@/hooks/ReadonlyContext';

import ColumnBlock from './ColumnBlock';

// jsdom doesn't support native dialog showModal/close
beforeEach(() => {
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '');
  });
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open');
  });
});

import { useSortable } from '@dnd-kit/sortable';
import { useDragContext } from '@/hooks/useDragAndDrop';

const defaultSortable = {
  attributes: {},
  listeners: {},
  setNodeRef: vi.fn(),
  transform: null,
  transition: undefined,
  isDragging: false,
  isOver: false,
};

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: vi.fn(() => ({ ...defaultSortable })),
  SortableContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  verticalListSortingStrategy: {},
  horizontalListSortingStrategy: {},
}));

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@dnd-kit/core')>();
  return {
    ...actual,
    DndContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  };
});

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: vi.fn(() => ({ activeType: null })),
}));

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<
    typeof useSortable
  >);
  vi.mocked(useDragContext).mockReturnValue({ activeType: null });
});

describe('ColumnBlock', () => {
  it('renders column children (element cards)', () => {
    mockFetchSuccess({});

    const children = [
      createSimpleElement({ id: 101, title: 'Content A' }),
      createSimpleElement({ id: 102, title: 'Content B' }),
    ];
    const column = createColumnNode({ children, childCount: 0 });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getAllByTestId('element-card')).toHaveLength(2);
    expect(screen.getByText('Content A')).toBeInTheDocument();
    expect(screen.getByText('Content B')).toBeInTheDocument();
  });

  it('shows empty state when no children and no allowed types', () => {
    mockFetchSuccess({});

    const column = createColumnNode({ children: null, childCount: 0, allowedTypes: null });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByText('No content blocks')).toBeInTheDocument();
  });

  it('does not show empty state when no children but allowedTypes exist', () => {
    mockFetchSuccess({});

    const column = createColumnNode({
      children: null,
      childCount: 0,
      allowedTypes: {
        'App\\Model\\ContentBlock': { label: 'Content Block', icon: '', description: '' },
      },
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.queryByText('No content blocks')).not.toBeInTheDocument();
  });

  it('does not show "Add content" button when allowedTypes is null', () => {
    mockFetchSuccess({});

    const column = createColumnNode({ allowedTypes: null });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.queryByTestId('add-content-button')).not.toBeInTheDocument();
  });

  it('does not show "Add content" button when allowedTypes is empty object', () => {
    mockFetchSuccess({});

    const column = createColumnNode({ allowedTypes: {} });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.queryByTestId('add-content-button')).not.toBeInTheDocument();
  });

  describe('CSS classes', () => {
    it('includes status modifier class for draft status', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        statusFlags: { addedtodraft: { text: 'Draft', title: 'Draft' } },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--draft');
    });

    it('includes status modifier class for modified status', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        statusFlags: { modified: { text: 'Modified', title: 'Modified' } },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--modified');
    });

    it('includes published status by default', () => {
      mockFetchSuccess({});

      const column = createColumnNode({ statusFlags: {} });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--published');
    });

    it('includes hidden class when column is not visible', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--hidden');
    });

    it('does not include hidden class when column is visible', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).not.toHaveClass('column-block--hidden');
    });

    it('includes collapsed class when the column is collapsed in the context', () => {
      mockFetchSuccess({});

      const column = createColumnNode({});

      renderWithProviders(<ColumnBlock column={column} />, {
        collapsedKeys: [column.nodeKey],
      });

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--collapsed');
    });

    it('includes drop-target class when isOver and activeType is column', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'column' });
      mockFetchSuccess({});

      const column = createColumnNode({});

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).toHaveClass('column-block--drop-target');
    });

    it('does not include drop-target class when isOver but activeType is not column', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: true,
      } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row' });
      mockFetchSuccess({});

      const column = createColumnNode({});

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).not.toHaveClass('column-block--drop-target');
    });

    it('does not include drop-target class when activeType is column but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({
        ...defaultSortable,
        isOver: false,
      } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'column' });
      mockFetchSuccess({});

      const column = createColumnNode({});

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-block')).not.toHaveClass('column-block--drop-target');
    });
  });

  describe('width picker', () => {
    it('shows current width label', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-badge')).toHaveTextContent('6/12');
    });

    it('shows "hidden" label when column is not visible', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-badge')).toHaveTextContent('hidden');
    });

    it('width selection calls updateGridSettings with new width and visible=true', async () => {
      const user = userEvent.setup();
      mockFetchSuccess({});

      const column = createColumnNode({
        id: 50,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

      await user.click(screen.getByTestId('column-badge'));

      const options = screen.getAllByRole('option');
      const option = options.find((opt) => opt.textContent === '8/12');
      expect(option).toBeDefined();
      await user.click(option!);

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
      });

      const [url, init] = getFetchCalls()[0];
      const body = JSON.parse(init!.body as string);

      expect(url).toContain('updateGridSettings');
      expect(body).toMatchObject({
        id: 50,
        viewport: 'md',
        width: 8,
        visible: true,
        offset: 0,
      });
    });

    it('selecting "hidden" calls updateGridSettings with visible=false', async () => {
      const user = userEvent.setup();
      mockFetchSuccess({});

      const column = createColumnNode({
        id: 51,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

      await user.click(screen.getByTestId('column-badge'));

      const options = screen.getAllByRole('option');
      const hiddenOption = options.find((opt) => opt.textContent === 'hidden');
      expect(hiddenOption).toBeDefined();
      await user.click(hiddenOption!);

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
      });

      const [, init] = getFetchCalls()[0];
      const body = JSON.parse(init!.body as string);

      expect(body).toMatchObject({
        id: 51,
        viewport: 'md',
        visible: false,
      });
    });

    it('clamps offset when selecting a width that makes current offset too large', async () => {
      const user = userEvent.setup();
      mockFetchSuccess({});

      const column = createColumnNode({
        id: 52,
        gridSettings: { default: { width: 4, offset: 7, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

      await user.click(screen.getByTestId('column-badge'));

      // Select width 10 — max offset is 12-10=2, but current offset is 7
      const options = screen.getAllByRole('option');
      const option = options.find((opt) => opt.textContent === '10/12');
      expect(option).toBeDefined();
      await user.click(option!);

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
      });

      const [, init] = getFetchCalls()[0];
      const body = JSON.parse(init!.body as string);

      expect(body).toMatchObject({
        id: 52,
        width: 10,
        offset: 2,
        visible: true,
      });
    });
  });

  describe('offset picker', () => {
    it('shows "none" label when offset is 0', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-offset-badge')).toHaveTextContent('none');
    });

    it('shows "+N" label when offset is non-zero', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 3, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-offset-badge')).toHaveTextContent('+3');
    });

    it('disabled when width equals column count', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-offset-badge')).toBeDisabled();
    });

    it('disabled when column is not visible', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: false }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-offset-badge')).toBeDisabled();
    });

    it('enabled when width < column count and visible', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('column-offset-badge')).not.toBeDisabled();
    });

    it('offset selection calls updateGridSettings with offset value', async () => {
      const user = userEvent.setup();
      mockFetchSuccess({});

      const column = createColumnNode({
        id: 53,
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

      await user.click(screen.getByTestId('column-offset-badge'));

      const options = screen.getAllByRole('option');
      const option = options.find((opt) => opt.textContent === '+3');
      expect(option).toBeDefined();
      await user.click(option!);

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
      });

      const [, init] = getFetchCalls()[0];
      const body = JSON.parse(init!.body as string);

      expect(body).toMatchObject({
        id: 53,
        viewport: 'md',
        offset: 3,
      });
    });
  });

  describe('column style (margin strategy)', () => {
    it('sets --col-width CSS variable based on width/columnCount', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      const { container } = renderWithProviders(<ColumnBlock column={column} />);

      const outerDiv = container.querySelector('.row-block__column') as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%');
    });

    it('sets --col-offset CSS variable when offset > 0', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 3, visible: true }, overrides: {} },
      });

      const { container } = renderWithProviders(<ColumnBlock column={column} />);

      const outerDiv = container.querySelector('.row-block__column') as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('25%');
    });

    it('does not set --col-offset when offset is 0', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
      });

      const { container } = renderWithProviders(<ColumnBlock column={column} />);

      const outerDiv = container.querySelector('.row-block__column') as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('');
    });
  });

  describe('column style (grid-placement strategy)', () => {
    it('sets --col-span and --col-start CSS variables', () => {
      // Override the adapter config to use grid-placement
      resetAdapterCache();
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement';

      mockFetchSuccess({});

      const column = createColumnNode({
        gridSettings: { default: { width: 4, offset: 2, visible: true }, overrides: {} },
      });

      const { container } = renderWithProviders(<ColumnBlock column={column} />);

      const outerDiv = container.querySelector('.row-block__column') as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-span')).toBe('4');
      // offset + 1 = 3 for grid-column-start
      expect(outerDiv.style.getPropertyValue('--col-start')).toBe('3');
    });
  });

  describe('element type picker', () => {
    it('shows "Add content" button when allowedTypes exist', () => {
      mockFetchSuccess({});

      const column = createColumnNode({
        children: null,
        childCount: 0,
        allowedTypes: {
          'App\\Model\\ContentBlock': { label: 'Content Block', icon: '', description: '' },
        },
      });

      renderWithProviders(<ColumnBlock column={column} />);

      expect(screen.getByTestId('add-content-button')).toHaveTextContent('+ Add content');
    });

    it('opens type picker on "Add content" click and calls createContentElement on select', async () => {
      const user = userEvent.setup();
      mockFetchSuccess({});

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
      });

      renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

      // Open the type picker
      await user.click(screen.getByTestId('add-content-button'));

      // The type picker dialog should be open
      expect(screen.getByTestId('element-type-picker')).toBeInTheDocument();

      // Click the tile
      await user.click(screen.getByTestId('element-type-tile'));

      await waitFor(() => {
        expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
      });

      const [url, init] = getFetchCalls()[0];
      const body = JSON.parse(init!.body as string);

      expect(url).toContain('createContent');
      expect(body).toMatchObject({
        className: 'App\\Model\\TextBlock',
        parentId: 60,
      });
    });
  });

  it('shows EmptyState when children is empty array and no allowedTypes', () => {
    mockFetchSuccess({});

    const column = createColumnNode({
      children: [] as never,
      childCount: 0,
      allowedTypes: null,
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByText('No content blocks')).toBeInTheDocument();
  });

  it('disables width picker when a drag is active', () => {
    vi.mocked(useDragContext).mockReturnValue({ activeType: 'column' });
    mockFetchSuccess({});

    const column = createColumnNode({
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByTestId('column-badge')).toBeDisabled();
  });

  it('closes the element type picker when close handler is invoked', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

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
    });

    renderWithProviders(<ColumnBlock column={column} />);

    // Open the picker
    await user.click(screen.getByTestId('add-content-button'));
    expect(screen.getByTestId('element-type-picker')).toBeInTheDocument();

    // Close it via the close button
    await user.click(screen.getByTestId('element-type-picker-close'));

    // The dialog should be closed (no open attribute)
    const dialog = screen.getByTestId('element-type-picker');
    expect(dialog).not.toHaveAttribute('open');
  });

  it('renders edit link when editLink is set', () => {
    mockFetchSuccess({});

    const column = createColumnNode({ editLink: '/admin/pages/edit/show/42' });

    renderWithProviders(<ColumnBlock column={column} />);

    const link = screen.getByTestId('column-edit-link');
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/42');
    expect(link).toHaveTextContent(column.title);
  });

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({});

    const column = createColumnNode({ editLink: null });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.queryByTestId('column-edit-link')).not.toBeInTheDocument();
    expect(screen.getByTestId('column-title')).toHaveTextContent(column.title);
  });

  describe('readonly mode', () => {
    // Pin the `children.length > 0 ? ... : <EmptyState ...>` ternary at
    // ColumnBlock.tsx:288 against EqualityOperator (`>= 0` / `<= 0`) and
    // ConditionalExpression mutations. Readonly is the isolated render path
    // (no sortable, no mutations, no picker) where the ternary survives.

    it('renders an ElementCard for each child and no empty-state', () => {
      mockFetchSuccess({});

      const children = [
        createSimpleElement({ id: 201, title: 'Readonly A' }),
        createSimpleElement({ id: 202, title: 'Readonly B' }),
        createSimpleElement({ id: 203, title: 'Readonly C' }),
      ];
      const column = createColumnNode({ children, childCount: 0 });

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <ColumnBlock column={column} />
        </ReadonlyProvider>,
      );

      expect(screen.getAllByTestId('element-card')).toHaveLength(3);
      expect(screen.getByText('Readonly A')).toBeInTheDocument();
      expect(screen.getByText('Readonly B')).toBeInTheDocument();
      expect(screen.getByText('Readonly C')).toBeInTheDocument();
      expect(screen.queryByText('No content blocks')).not.toBeInTheDocument();
    });

    it('renders the empty-state message and no ElementCards when children are empty', () => {
      mockFetchSuccess({});

      const column = createColumnNode({ children: null, childCount: 0 });

      renderWithProviders(
        <ReadonlyProvider value={true}>
          <ColumnBlock column={column} />
        </ReadonlyProvider>,
      );

      expect(screen.getByText('No content blocks')).toBeInTheDocument();
      expect(screen.queryAllByTestId('element-card')).toHaveLength(0);
    });
  });
});
