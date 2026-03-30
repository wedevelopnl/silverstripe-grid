import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess, getFetchCalls } from '@/testing/mockFetch';
import { createEnrichedColumn, createEnrichedElement } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import ColumnBlock from './ColumnBlock';

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

vi.mock('@dnd-kit/core', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@dnd-kit/core')>();
  return {
    ...actual,
    DndContext: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  };
});

vi.mock('@/hooks/useDragAndDrop', () => ({
  useDragContext: () => ({ activeType: null }),
}));

describe('ColumnBlock', () => {
  it('renders column children (element cards)', () => {
    mockFetchSuccess({});

    const children = [
      createEnrichedElement({ id: 101, title: 'Content A' }),
      createEnrichedElement({ id: 102, title: 'Content B' }),
    ];
    const column = createEnrichedColumn({ children, childCount: 0 });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getAllByTestId('element-card')).toHaveLength(2);
    expect(screen.getByText('Content A')).toBeInTheDocument();
    expect(screen.getByText('Content B')).toBeInTheDocument();
  });

  it('shows empty state when no children and no allowed types', () => {
    mockFetchSuccess({});

    const column = createEnrichedColumn({ children: null, childCount: 0, allowedTypes: null });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByText('No content blocks')).toBeInTheDocument();
  });

  it('width picker shows current width label', () => {
    mockFetchSuccess({});

    const column = createEnrichedColumn({
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByTestId('column-badge')).toHaveTextContent('6/12');
  });

  it('width selection calls updateGridSettings mutation', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    const column = createEnrichedColumn({
      id: 50,
      gridSettings: { default: { width: 6, offset: 0, visible: true }, overrides: {} },
    });

    renderWithProviders(<ColumnBlock column={column} />, { viewport: 'md' });

    // Open the width picker
    await user.click(screen.getByTestId('column-badge'));

    // Select a different width option
    const listbox = screen.getByTestId('column-badge-listbox');
    const option8 = listbox.querySelector('[aria-selected="false"]');
    // Click the "8/12" option (value 8)
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
    });
  });

  it('offset picker disabled when width equals column count', () => {
    mockFetchSuccess({});

    const column = createEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: {} },
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByTestId('column-offset-badge')).toBeDisabled();
  });

  it('shows "Add content" button when allowedTypes exist', () => {
    mockFetchSuccess({});

    const column = createEnrichedColumn({
      children: null,
      childCount: 0,
      allowedTypes: { 'App\\Model\\ContentBlock': { label: 'Content Block', icon: '', description: '' } },
    });

    renderWithProviders(<ColumnBlock column={column} />);

    expect(screen.getByTestId('add-content-button')).toHaveTextContent('+ Add content');
  });
});
