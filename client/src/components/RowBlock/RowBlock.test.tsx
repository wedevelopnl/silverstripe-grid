import { screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createEnrichedRow } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import RowBlock from './RowBlock';

vi.mock('@dnd-kit/sortable', () => ({
  useSortable: () => ({
    attributes: {},
    listeners: {},
    setNodeRef: vi.fn(),
    transform: null,
    transition: undefined,
    isDragging: false,
    isOver: false,
  }),
  SortableContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  verticalListSortingStrategy: {},
  horizontalListSortingStrategy: {},
}));

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: () => ({ activeType: null }),
}));

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
});
