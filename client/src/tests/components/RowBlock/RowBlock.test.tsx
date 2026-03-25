import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import RowBlock from '@/components/RowBlock/RowBlock';
import type { EnrichedRowNode, EnrichedColumnNode } from '@/types/enriched';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
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
  return {
    ...actual,
    useSortable: () => ({
      attributes: {},
      listeners: undefined,
      setNodeRef: () => {},
      transform: null,
      transition: null,
      isDragging: false,
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

const { mockViewports, mockResolveViewportSettings } = vi.hoisted(() => {
  const viewports = [
    { key: 'xs', label: 'XS' }, { key: 'sm', label: 'SM' }, { key: 'md', label: 'MD' },
    { key: 'lg', label: 'LG' }, { key: 'xl', label: 'XL' }, { key: 'xxl', label: 'XXL' },
  ];
  const resolve = (gridSettings: { default: { width: number; offset: number; visible: boolean }; overrides: Record<string, { width: number; offset: number; visible: boolean }> }, activeViewport: string) => {
    return gridSettings.overrides[activeViewport] ?? gridSettings.default;
  };
  return { mockViewports: viewports, mockResolveViewportSettings: resolve };
});

vi.mock('@/utils/gridAdapter', () => ({
  getColumnCount: vi.fn(() => 12),
  getViewports: vi.fn(() => mockViewports),
  getOffsetStrategy: vi.fn(() => 'margin'),
  getDefaultViewport: vi.fn(() => 'md'),
  getWidthOptions: vi.fn(() => [
    ...Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `${i + 1}/12` })),
    { value: 'hidden', label: 'hidden' },
  ]),
  getOffsetOptions: vi.fn(() =>
    Array.from({ length: 12 }, (_, i) => ({ value: i, label: i === 0 ? 'none' : `+${i}` })),
  ),
  resolveViewportSettings: vi.fn(mockResolveViewportSettings),
}));

function makeColumn(id: number, title: string, overrides: Partial<EnrichedColumnNode> = {}): EnrichedColumnNode {
  return {
    id,
    parentId: 200,
    title,
    blockSchema: {
      typeName: 'WeDevelop\\Grid\\Elements\\Column',
      label: 'Column',
      icon: 'font-icon-block-content',
      type: 'Column',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'column' as const,
    allowedTypes: null,
    children: null,
    gridSettings: {
      default: { width: 12, offset: 0, visible: true },
      overrides: { md: { width: 6, offset: 0, visible: true } },
    },
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `column-${id}`,
    childSortableIds: [],
    ...overrides,
  };
}

function makeRow(overrides: Partial<EnrichedRowNode> = {}): EnrichedRowNode {
  const id = overrides.id ?? 20;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 300,
    title: 'Row',
    blockSchema: {
      typeName: 'WeDevelop\\Grid\\Elements\\Row',
      label: 'Row',
      icon: 'font-icon-block-content',
      type: 'Row',
      title: '',
      summary: '',
    },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'row',
    allowedTypes: null,
    children,
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `row-${id}`,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}

describe('RowBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setIsOver(false);
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
    vi.mocked(getColumnCount).mockReturnValue(12);
  });

  it('renders title as an h3 heading', () => {
    const row = makeRow({ title: 'Main Row' });

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
    const row = makeRow();

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
    const row = makeRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const columnContainer = container.querySelector('.row-block__columns--grid');
    expect(columnContainer).not.toBeNull();
    expect((columnContainer as HTMLElement).style.getPropertyValue('--grid-columns')).toBe('12');
  });

  it('renders column children as ColumnBlocks', () => {
    const row = makeRow({
      children: [
        makeColumn(10, 'Left Column'),
        makeColumn(11, 'Right Column'),
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
    const row = makeRow({ children: null });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No columns yet')).toBeDefined();
    expect(screen.getByText('Add Column')).toBeDefined();
  });

  it('renders add child empty state when children is empty array', () => {
    const row = makeRow({ children: [] });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No columns yet')).toBeDefined();
    expect(screen.getByText('Add Column')).toBeDefined();
  });

  it('applies draft publication state modifier class', () => {
    const row = makeRow({
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
    const row = makeRow({
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
    const row = makeRow({
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
    const row = makeRow({
      children: [
        makeColumn(10, 'Column', {
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

    const row = makeRow({
      children: [
        makeColumn(10, 'Column', {
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
    const row = makeRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper('md', [], 'row') },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(true);
  });

  it('does not apply --drop-target when isOver is true but activeType is not row', () => {
    setIsOver(true);
    const row = makeRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(false);
  });

  it('does not apply --drop-target modifier when isOver is false', () => {
    const row = makeRow();

    const { container } = render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.row-block');
    expect(outer?.classList.contains('row-block--drop-target')).toBe(false);
  });

  describe('collapse', () => {
    it('renders a collapse toggle button', () => {
      const row = makeRow();

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('collapse-toggle')).toBeDefined();
    });

    it('wires toggle to CollapseToggle onToggle', async () => {
      const toggle = vi.fn();
      const row = makeRow({ id: 77, toggle });
      const user = userEvent.setup();

      render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('collapse-toggle'));
      expect(toggle).toHaveBeenCalledOnce();
    });

    it('applies --collapsed modifier when collapsed', () => {
      const row = makeRow({ isCollapsed: true });

      const { container } = render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.row-block');
      expect(outer?.classList.contains('row-block--collapsed')).toBe(true);
    });

    it('does not apply --collapsed modifier when expanded', () => {
      const row = makeRow({ isCollapsed: false });

      const { container } = render(
        <RowBlock row={row} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.row-block');
      expect(outer?.classList.contains('row-block--collapsed')).toBe(false);
    });
  });

  it('renders ElementActions with actions menu', () => {
    const row = makeRow({ canDelete: true });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  describe('edit link', () => {
    it('renders title as a link when editLink is present', () => {
      const row = makeRow({
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
      const row = makeRow({ title: 'Main Row', editLink: null });

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
    const row = makeRow({ title: 'Main Row' });

    render(
      <RowBlock row={row} />,
      { wrapper: createDndWrapper() },
    );

    const handle = screen.getByTestId('drag-handle');
    expect(handle).toBeDefined();
    expect(handle.getAttribute('aria-label')).toBe('Move Main Row');
  });
});
