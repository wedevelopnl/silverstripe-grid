import { screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { resetAdapterCache } from '@/utils/gridAdapter';
import { useSortable } from '@dnd-kit/sortable';
import { useDragContext } from '@/hooks/useDragAndDrop';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createEnrichedRow } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import RowBlock from './RowBlock';

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

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: vi.fn(() => ({ activeType: null })),
}));

afterEach(() => {
  vi.mocked(useSortable).mockReturnValue({ ...defaultSortable } as unknown as ReturnType<typeof useSortable>);
  vi.mocked(useDragContext).mockReturnValue({ activeType: null });
});

describe('RowBlock', () => {
  it('renders row title', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ title: 'Main Row' });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.getByTestId('row-title')).toHaveTextContent('Main Row');
  });

  it('renders child columns', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ columnCount: 3 });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.getAllByTestId('column-block')).toHaveLength(3);
  });

  it('shows empty state when no children', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ children: null });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument();
    expect(screen.getByText('No columns yet')).toBeInTheDocument();
  });

  it('shows append AddChildButton when children exist', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ columnCount: 1 });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.getByTestId('add-child-append')).toBeInTheDocument();
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument();
  });

  it('shows empty state when children is an empty array', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ children: [] as never });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument();
  });

  describe('CSS classes', () => {
    it('includes draft status modifier', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({ statusFlags: { addedtodraft: { text: 'Draft', title: 'Draft' } } });

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).toHaveClass('row-block--draft');
    });

    it('includes modified status modifier', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({ statusFlags: { modified: { text: 'Modified', title: 'Modified' } } });

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).toHaveClass('row-block--modified');
    });

    it('includes published status by default', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({ statusFlags: {} });

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).toHaveClass('row-block--published');
    });

    it('includes collapsed class when collapsed', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({});
      (row as { isCollapsed: boolean }).isCollapsed = true;

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).toHaveClass('row-block--collapsed');
    });

    it('does not include collapsed class when expanded', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({});

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).not.toHaveClass('row-block--collapsed');
    });

    it('includes drop-target class when isOver and activeType is row', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: true } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row' });
      mockFetchSuccess({});

      const row = createEnrichedRow({});

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).toHaveClass('row-block--drop-target');
    });

    it('does not include drop-target class when isOver but activeType is not row', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: true } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section' });
      mockFetchSuccess({});

      const row = createEnrichedRow({});

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).not.toHaveClass('row-block--drop-target');
    });

    it('does not include drop-target class when activeType is row but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: false } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row' });
      mockFetchSuccess({});

      const row = createEnrichedRow({});

      renderWithProviders(<RowBlock row={row} />);

      expect(screen.getByTestId('row-block')).not.toHaveClass('row-block--drop-target');
    });
  });

  describe('layout mode', () => {
    it('uses flex layout when offset strategy is margin', () => {
      mockFetchSuccess({});

      const row = createEnrichedRow({ columnCount: 1 });

      const { container } = renderWithProviders(<RowBlock row={row} />);

      const columnsDiv = container.querySelector('.row-block__columns');
      expect(columnsDiv).toHaveClass('row-block__columns--flex');
      expect(columnsDiv).not.toHaveClass('row-block__columns--grid');
    });

    it('uses grid layout when offset strategy is grid-placement', () => {
      // Override adapter config for this test
      resetAdapterCache();
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement';

      mockFetchSuccess({});

      const row = createEnrichedRow({ columnCount: 1 });

      const { container } = renderWithProviders(<RowBlock row={row} />);

      const columnsDiv = container.querySelector('.row-block__columns');
      expect(columnsDiv).toHaveClass('row-block__columns--grid');
      expect(columnsDiv).not.toHaveClass('row-block__columns--flex');
    });

    it('sets --grid-columns CSS variable in grid mode', () => {
      resetAdapterCache();
      window.ss!.config.sections[0].gridAdapter!.offsetStrategy = 'grid-placement';

      mockFetchSuccess({});

      const row = createEnrichedRow({ columnCount: 1 });

      const { container } = renderWithProviders(<RowBlock row={row} />);

      const columnsDiv = container.querySelector('.row-block__columns') as HTMLElement;
      expect(columnsDiv.style.getPropertyValue('--grid-columns')).toBe('12');
    });

    it('does not set --grid-columns CSS variable in flex mode', () => {
      resetAdapterCache();
      mockFetchSuccess({});

      const row = createEnrichedRow({ columnCount: 1 });

      const { container } = renderWithProviders(<RowBlock row={row} />);

      const columnsDiv = container.querySelector('.row-block__columns') as HTMLElement;
      expect(columnsDiv.style.getPropertyValue('--grid-columns')).toBe('');
    });
  });

  it('renders edit link when editLink is set', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ editLink: '/admin/pages/edit/show/10' });

    renderWithProviders(<RowBlock row={row} />);

    const link = screen.getByTestId('row-edit-link');
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/10');
    expect(link).toHaveTextContent(row.title);
  });

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({});

    const row = createEnrichedRow({ editLink: null });

    renderWithProviders(<RowBlock row={row} />);

    expect(screen.queryByTestId('row-edit-link')).not.toBeInTheDocument();
    expect(screen.getByTestId('row-title')).toHaveTextContent(row.title);
  });
});
