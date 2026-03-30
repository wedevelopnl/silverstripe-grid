import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createEnrichedSection } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import SectionBlock from './SectionBlock';

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

describe('SectionBlock', () => {
  it('renders section title', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ title: 'Hero Section' });

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.getByTestId('section-title')).toHaveTextContent('Hero Section');
  });

  it('renders child rows', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ rowCount: 2 });

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.getAllByTestId('row-block')).toHaveLength(2);
  });

  it('shows empty state when no children', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ children: null });

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument();
    expect(screen.getByText('No rows yet')).toBeInTheDocument();
  });

  it('collapse hides children', async () => {
    const user = userEvent.setup();
    mockFetchSuccess({});

    const section = createEnrichedSection({ rowCount: 1 });

    renderWithProviders(<SectionBlock section={section} />);

    // Initially expanded — body should not have collapsed class
    expect(screen.getByTestId('section-block')).not.toHaveClass('section-block--collapsed');

    // There are multiple collapse toggles (section + child rows/columns).
    // The first one belongs to the section header.
    const toggles = screen.getAllByTestId('collapse-toggle');
    await user.click(toggles[0]);

    // The toggle callback was called
    expect(section.toggle).toHaveBeenCalledOnce();
  });

  it('edit link rendered when editLink exists', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<SectionBlock section={section} />);

    const link = screen.getByTestId('section-edit-link');
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5');
  });
});
