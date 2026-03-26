import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import RowBlock from '@/components/RowBlock/RowBlock';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { makeEnrichedColumn, makeEnrichedRow } from '@/tests/helpers/enrichedFactories';
import {
  getOffsetStrategy,
  getColumnCount,
} from '@/utils/gridAdapter';

const { getIsOver, setIsOver } = vi.hoisted(() => {
  let value = false;
  return {
    getIsOver: () => value,
    setIsOver: (v: boolean) => { value = v; },
  };
});

vi.mock('@dnd-kit/sortable', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@dnd-kit/sortable')>();
  const { DEFAULT_SORTABLE_RETURN } = await import('@/tests/helpers/mockData');
  return {
    ...actual,
    useSortable: () => ({
      ...DEFAULT_SORTABLE_RETURN,
      isOver: getIsOver(),
    }),
  };
});

vi.mock('@/api/endpoints', () => ({
  createElement: vi.fn(),
  createContentElement: vi.fn(),
  archiveElement: vi.fn(),
  duplicateElement: vi.fn(),
  duplicateToElement: vi.fn(),
  updateGridSettings: vi.fn(),
  fetchPages: vi.fn().mockResolvedValue([]),
  fetchZones: vi.fn().mockResolvedValue([]),
  fetchAcceptableContainers: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/utils/gridAdapter', async () => {
  const { MOCK_VIEWPORTS, resolveViewportSettingsImpl, defaultWidthOptions, defaultOffsetOptions } = await import('@/tests/helpers/mockData');
  return {
    getColumnCount: vi.fn(() => 12),
    getViewports: vi.fn(() => MOCK_VIEWPORTS),
    getOffsetStrategy: vi.fn(() => 'margin'),
    getDefaultViewport: vi.fn(() => 'md'),
    getWidthOptions: vi.fn(() => defaultWidthOptions()),
    getOffsetOptions: vi.fn(() => defaultOffsetOptions()),
    resolveViewportSettings: vi.fn(resolveViewportSettingsImpl),
  };
});

describe('RowBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setIsOver(false);
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
    vi.mocked(getColumnCount).mockReturnValue(12);
  });

  it('renders title as an h3 heading', () => {
    const row = makeEnrichedRow({ title: 'Main Row' });

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const heading = screen.getByRole('heading', { level: 3 });
    expect(heading.textContent).toBe('Main Row');
    expect(container.querySelector('.row-block__icon.font-icon-block-content')).not.toBeNull();
  });

  it('applies flex layout class when offsetStrategy is margin', () => {
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
    const row = makeEnrichedRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const columnContainer = container.querySelector('.row-block__columns--flex');
    expect(columnContainer).not.toBeNull();
    expect((columnContainer as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('');
  });

  it('applies grid layout class when offsetStrategy is grid-placement', () => {
    vi.mocked(getOffsetStrategy).mockReturnValue('grid-placement');
    const row = makeEnrichedRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const columnContainer = container.querySelector('.row-block__columns--grid');
    expect(columnContainer).not.toBeNull();
    expect((columnContainer as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12');
  });

  it('renders column children as ColumnBlocks', () => {
    const row = makeEnrichedRow({
      children: [
        makeEnrichedColumn({ id: 10, title: 'Left Column' }),
        makeEnrichedColumn({ id: 11, title: 'Right Column' }),
      ],
    });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const badges = screen.getAllByTestId('column-badge');
    expect(badges.length).toBe(2);
  });

  it('renders add child empty state when children is null', () => {
    const row = makeEnrichedRow({ children: null });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No columns yet')).toBeDefined();
    expect(screen.getByText('Add Column')).toBeDefined();
  });

  it('renders add child empty state when children is empty array', () => {
    const row = makeEnrichedRow({ children: [] });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No columns yet')).toBeDefined();
    expect(screen.getByText('Add Column')).toBeDefined();
  });

  it('applies draft publication state modifier class', () => {
    const row = makeEnrichedRow({
      statusFlags: { addedtodraft: { text: 'Draft', title: 'Item has not been published yet' } },
    });

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--draft')).toBe(true);
  });

  it('applies published publication state modifier class', () => {
    const row = makeEnrichedRow({
      statusFlags: {},
    });

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--published')).toBe(true);
  });

  it('applies modified publication state modifier class', () => {
    const row = makeEnrichedRow({
      statusFlags: { modified: { text: 'Modified', title: 'Item has unpublished changes' } },
    });

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--modified')).toBe(true);
  });

  it('child columns resolve settings based on activeViewport from context', () => {
    const row = makeEnrichedRow({
      children: [
        makeEnrichedColumn({
          id: 10,
          title: 'Column',
          gridSettings: {
            default: { width: 12, offset: 0, visible: true },
            overrides: {
              md: { width: 6, offset: 0, visible: true },
              lg: { width: 4, offset: 0, visible: true },
            },
          },
        }),
      ],
    });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper('lg') },
    );

    // When activeViewport is "lg", the ColumnBlock should use lg settings (width 4)
    const badge = screen.getByTestId('column-badge');
    expect(badge.textContent).toBe('4/12');
  });

  it('child columns use getColumnCount from gridAdapter for badge display', () => {
    vi.mocked(getColumnCount).mockReturnValue(16);

    const row = makeEnrichedRow({
      children: [
        makeEnrichedColumn({
          id: 10,
          title: 'Column',
          gridSettings: {
            default: { width: 12, offset: 0, visible: true },
            overrides: { md: { width: 6, offset: 0, visible: true } },
          },
        }),
      ],
    });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    // ColumnBlock shows width/columnCount, so with columnCount=16 and width=6
    const badge = screen.getByTestId('column-badge');
    expect(badge.textContent).toBe('6/16');
  });

  it('applies --drop-target modifier when isOver is true and activeType is row', () => {
    setIsOver(true);
    const row = makeEnrichedRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper('md', [], 'row') },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(true);
  });

  it('does not apply --drop-target when isOver is true but activeType is not row', () => {
    setIsOver(true);
    const row = makeEnrichedRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(false);
  });

  it('does not apply --drop-target modifier when isOver is false', () => {
    const row = makeEnrichedRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(false);
  });

  describe('collapse', () => {
    it('renders a collapse toggle button', () => {
      const row = makeEnrichedRow();

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('collapse-toggle')).toBeDefined();
    });

    it('wires toggle to CollapseToggle onToggle', async () => {
      const toggle = vi.fn();
      const row = makeEnrichedRow({ id: 77, toggle });
      const user = userEvent.setup();

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('collapse-toggle'));
      expect(toggle).toHaveBeenCalledOnce();
    });

    it('applies --collapsed modifier when collapsed', () => {
      const row = makeEnrichedRow({ isCollapsed: true });

      const { container } = render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.row-block');
      expect(outer?.classList.contains('row-block--collapsed')).toBe(true);
    });

    it('does not apply --collapsed modifier when expanded', () => {
      const row = makeEnrichedRow({ isCollapsed: false });

      const { container } = render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.row-block');
      expect(outer?.classList.contains('row-block--collapsed')).toBe(false);
    });
  });

  it('renders ElementActions with actions menu', () => {
    const row = makeEnrichedRow({ canDelete: true });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  describe('edit link', () => {
    it('renders title as a link when editLink is present', () => {
      const row = makeEnrichedRow({
        title: 'Main Row',
        editLink: '/admin/pages/edit/EditForm/42/field/GridEditor/item/20/edit',
      });

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const link = screen.getByTestId('row-edit-link');
      expect(link.tagName).toBe('A');
      expect(link.getAttribute('href')).toBe('/admin/pages/edit/EditForm/42/field/GridEditor/item/20/edit');
      expect(link.textContent).toBe('Main Row');
    });

    it('renders plain heading without link when editLink is null', () => {
      const row = makeEnrichedRow({ title: 'Main Row', editLink: null });

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const heading = screen.getByRole('heading', { level: 3 });
      expect(heading.textContent).toBe('Main Row');
      expect(screen.queryByTestId('row-edit-link')).toBeNull();
    });
  });

  it('renders a drag handle', () => {
    const row = makeEnrichedRow({ title: 'Main Row' });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const handle = screen.getByTestId('drag-handle');
    expect(handle).toBeDefined();
    expect(handle.getAttribute('aria-label')).toBe('Move Main Row');
  });
});
