import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useSortable } from '@dnd-kit/sortable';
import { useDragContext } from '@/hooks/useDragAndDrop';

import { mockFetchSuccess } from '@/testing/mockFetch';
import { createEnrichedSection } from '@/testing/enrichedFactories';
import { renderWithProviders } from '@/testing/renderWithProviders';

import SectionBlock from './SectionBlock';

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

  it('shows append AddChildButton when children exist', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ rowCount: 1 });

    renderWithProviders(<SectionBlock section={section} />);

    // Section's own append button + child row/column append buttons
    const appendButtons = screen.getAllByTestId('add-child-append');
    expect(appendButtons.length).toBeGreaterThan(0);
    // The section's button says "Add Row"
    expect(screen.getByText('Add Row')).toBeInTheDocument();
    expect(screen.queryByTestId('add-child-empty')).not.toBeInTheDocument();
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

  it('applies collapsed class when isCollapsed is true', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ rowCount: 1 });
    (section as { isCollapsed: boolean }).isCollapsed = true;

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.getByTestId('section-block')).toHaveClass('section-block--collapsed');
  });

  it('edit link rendered when editLink exists', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ editLink: '/admin/pages/edit/show/5' });

    renderWithProviders(<SectionBlock section={section} />);

    const link = screen.getByTestId('section-edit-link');
    expect(link).toHaveAttribute('href', '/admin/pages/edit/show/5');
    expect(link).toHaveTextContent(section.title);
  });

  it('renders title as plain text when editLink is null', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ editLink: null });

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.queryByTestId('section-edit-link')).not.toBeInTheDocument();
    expect(screen.getByTestId('section-title')).toHaveTextContent(section.title);
  });

  it('shows empty state when children is an empty array', () => {
    mockFetchSuccess({});

    const section = createEnrichedSection({ children: [] as never });

    renderWithProviders(<SectionBlock section={section} />);

    expect(screen.getByTestId('add-child-empty')).toBeInTheDocument();
  });

  describe('CSS classes', () => {
    it('includes draft status modifier', () => {
      mockFetchSuccess({});

      const section = createEnrichedSection({ statusFlags: { addedtodraft: { text: 'Draft', title: 'Draft' } } });

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).toHaveClass('section-block--draft');
    });

    it('includes modified status modifier', () => {
      mockFetchSuccess({});

      const section = createEnrichedSection({ statusFlags: { modified: { text: 'Modified', title: 'Modified' } } });

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).toHaveClass('section-block--modified');
    });

    it('includes published status by default', () => {
      mockFetchSuccess({});

      const section = createEnrichedSection({ statusFlags: {} });

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).toHaveClass('section-block--published');
    });

    it('includes drop-target class when isOver and activeType is section', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: true } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section' });
      mockFetchSuccess({});

      const section = createEnrichedSection({});

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).toHaveClass('section-block--drop-target');
    });

    it('does not include drop-target class when isOver but activeType is not section', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: true } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'row' });
      mockFetchSuccess({});

      const section = createEnrichedSection({});

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).not.toHaveClass('section-block--drop-target');
    });

    it('does not include drop-target class when activeType is section but not isOver', () => {
      vi.mocked(useSortable).mockReturnValue({ ...defaultSortable, isOver: false } as unknown as ReturnType<typeof useSortable>);
      vi.mocked(useDragContext).mockReturnValue({ activeType: 'section' });
      mockFetchSuccess({});

      const section = createEnrichedSection({});

      renderWithProviders(<SectionBlock section={section} />);

      expect(screen.getByTestId('section-block')).not.toHaveClass('section-block--drop-target');
    });
  });
});
