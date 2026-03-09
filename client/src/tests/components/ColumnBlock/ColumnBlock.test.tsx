import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ColumnBlock from '@/components/ColumnBlock/ColumnBlock';
import type { EnrichedColumnNode } from '@/types/enriched';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { getWidthClass, getOffsetClass, getColumnCount } from '@/utils/gridAdapter';

const { getIsOver, setIsOver } = vi.hoisted(() => {
  let value = false;
  return {
    getIsOver: () => value,
    setIsOver: (v: boolean) => { value = v; },
  };
});

vi.mock('@/api/endpoints', () => ({
  createElement: vi.fn(),
  createContentElement: vi.fn(),
  updateGridSettings: vi.fn(),
}));

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

const { mockViewports, mockResolveViewportSettings } = vi.hoisted(() => {
  const viewports = [
    { key: 'xs', label: 'XS' },
    { key: 'sm', label: 'SM' },
    { key: 'md', label: 'MD' },
    { key: 'lg', label: 'LG' },
    { key: 'xl', label: 'XL' },
    { key: 'xxl', label: 'XXL' },
  ];

  const resolve = (gridSettings: Record<string, unknown>, activeViewport: string) => {
    let effective = { width: 12, offset: 0, visible: true };
    for (const vp of viewports) {
      const override = gridSettings[vp.key];
      if (override) effective = { ...effective, ...(override as typeof effective) };
      if (vp.key === activeViewport) break;
    }
    return effective;
  };

  return { mockViewports: viewports, mockResolveViewportSettings: resolve };
});

vi.mock('@/utils/gridAdapter', () => ({
  getColumnCount: vi.fn(() => 12),
  getViewports: vi.fn(() => mockViewports),
  getWidthClass: vi.fn((width: number) => `col-${width}`),
  getOffsetClass: vi.fn((offset: number) => `offset-${offset}`),
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

function makeColumn(overrides: Partial<EnrichedColumnNode> = {}): EnrichedColumnNode {
  const id = overrides.id ?? 10;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 200,
    title: 'Column',
    blockSchema: {
      typeName: 'WeDevelop\\Grid\\Elements\\Column',
      label: 'Column',
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
    containerType: 'column',
    allowedTypes: null,
    gridSettings: {
      md: { width: 6, offset: 0, visible: true },
    },
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `column-${id}`,
    children,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}

describe('ColumnBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setIsOver(false);
    vi.mocked(getColumnCount).mockReturnValue(12);
    vi.mocked(getWidthClass).mockImplementation((width: number) => `col-${width}`);
    vi.mocked(getOffsetClass).mockImplementation((offset: number) => `offset-${offset}`);
  });

  it('renders fraction badge for the active viewport', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('6/12')).toBeDefined();
  });

  it('applies width class from getWidthClass()', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('col-6')).toBe(true);
  });

  it('applies offset class from getOffsetClass() when offset > 0', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 4, offset: 2, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('offset-2')).toBe(true);
  });

  it('does not apply offset class when offset is 0', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('offset-0')).toBe(false);
  });

  it('renders child elements as ElementCards', () => {
    const column = makeColumn({
      children: [
        {
          id: 100,
          parentId: 100,
          title: 'Hero Banner',
          blockSchema: {
            typeName: 'Content',
            label: 'Content',
            type: 'Content',
            title: '',
            summary: 'Hero content',
          },
          obsoleteClassName: null,
          version: 1,
          isPublished: true,
          isLiveVersion: true,
          canDelete: true,
          canPublish: true,
          canUnpublish: false,
          canCreate: true,
          editLink: null,
          statusFlags: {},
          sortableId: 'element-100',
        },
        {
          id: 101,
          parentId: 100,
          title: 'Text Block',
          blockSchema: {
            typeName: 'Content',
            label: 'Content',
            type: 'Content',
            title: '',
            summary: 'Text content',
          },
          obsoleteClassName: null,
          version: 1,
          canDelete: true,
          canPublish: true,
          canUnpublish: false,
          canCreate: true,
          editLink: null,
          statusFlags: {},
          sortableId: 'element-101',
        },
      ],
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('Hero Banner')).toBeDefined();
    expect(screen.getByText('Text Block')).toBeDefined();
  });

  it('renders EmptyState when children is null', () => {
    const column = makeColumn({ children: null });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No content blocks')).toBeDefined();
  });

  it('renders EmptyState when children is empty array', () => {
    const column = makeColumn({ children: [] });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No content blocks')).toBeDefined();
  });

  it('applies hidden modifier when visible is false', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: false } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--hidden')).toBe(true);
  });

  it('does not apply hidden modifier when visible is true', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--hidden')).toBe(false);
  });

  it('shows "hidden" instead of fraction badge when not visible', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: false } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('hidden')).toBeDefined();
    expect(screen.queryByText('6/12')).toBeNull();
  });

  it('applies publication state modifier class for draft column', () => {
    const column = makeColumn({
      statusFlags: { addedtodraft: { text: 'Draft', title: 'Item has not been published yet' } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--draft')).toBe(true);
  });

  it('applies publication state modifier class for published column', () => {
    const column = makeColumn({
      statusFlags: {},
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--published')).toBe(true);
  });

  it('applies publication state modifier class for modified column', () => {
    const column = makeColumn({
      statusFlags: { modified: { text: 'Modified', title: 'Item has unpublished changes' } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--modified')).toBe(true);
  });

  it('cascades smaller viewport settings to larger viewport', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    // lg inherits md=6 via cascade
    expect(screen.getByText('6/12')).toBeDefined();
    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('col-6')).toBe(true);
  });

  it('cascades offset from smaller viewport', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 3, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    const outerDiv = container.firstElementChild;
    // lg inherits md offset=3 via cascade
    expect(outerDiv?.classList.contains('offset-3')).toBe(true);
  });

  it('cascades hidden visibility from smaller viewport', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: false } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    const inner = container.querySelector('.column-block');
    // lg inherits md hidden state via cascade
    expect(inner?.classList.contains('column-block--hidden')).toBe(true);
    expect(screen.getByText('hidden')).toBeDefined();
  });

  it('calls getWidthClass with the resolved column width', () => {
    vi.mocked(getWidthClass).mockReturnValue('custom-col-8');
    const column = makeColumn({
      gridSettings: { md: { width: 8, offset: 0, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(getWidthClass).toHaveBeenCalledWith(8);
    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('custom-col-8')).toBe(true);
  });

  it('calls getOffsetClass with the resolved column offset', () => {
    vi.mocked(getOffsetClass).mockReturnValue('custom-offset-3');
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 3, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(getOffsetClass).toHaveBeenCalledWith(3);
    const outerDiv = container.firstElementChild;
    expect(outerDiv?.classList.contains('custom-offset-3')).toBe(true);
  });

  it('does not call getOffsetClass when offset is 0', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(getOffsetClass).not.toHaveBeenCalled();
  });

  it('applies --drop-target modifier when isOver is true and activeType is column', () => {
    setIsOver(true);
    const column = makeColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'column') },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(true);
  });

  it('does not apply --drop-target when isOver is true but activeType is not column', () => {
    setIsOver(true);
    const column = makeColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(false);
  });

  it('does not apply --drop-target modifier when isOver is false', () => {
    const column = makeColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(false);
  });

  describe('collapse', () => {
    it('renders a collapse toggle button', () => {
      const column = makeColumn();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('collapse-toggle')).toBeDefined();
    });

    it('wires toggle to CollapseToggle onToggle', async () => {
      const toggle = vi.fn();
      const column = makeColumn({ id: 55, toggle });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('collapse-toggle'));
      expect(toggle).toHaveBeenCalledOnce();
    });

    it('applies --collapsed modifier when collapsed', () => {
      const column = makeColumn({ isCollapsed: true });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const inner = container.querySelector('.column-block');
      expect(inner?.classList.contains('column-block--collapsed')).toBe(true);
    });

    it('does not apply --collapsed modifier when expanded', () => {
      const column = makeColumn({ isCollapsed: false });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const inner = container.querySelector('.column-block');
      expect(inner?.classList.contains('column-block--collapsed')).toBe(false);
    });
  });

  it('renders a drag handle', () => {
    const column = makeColumn({ title: 'Left Column' });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const handle = screen.getByTestId('drag-handle');
    expect(handle).toBeDefined();
    expect(handle.getAttribute('aria-label')).toBe('Move Left Column');
  });

  describe('add content button', () => {
    beforeEach(() => {
      HTMLDialogElement.prototype.showModal = vi.fn();
      HTMLDialogElement.prototype.close = vi.fn();
    });

    it('shows "Add content" button when allowedTypes has entries', () => {
      const column = makeColumn({
        allowedTypes: { 'App\\Model\\Text': { label: 'Text', icon: 'font-icon-block-content', description: '' } },
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('add-content-button')).toBeDefined();
    });

    it('does not show "Add content" button when allowedTypes is null', () => {
      const column = makeColumn({ allowedTypes: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByTestId('add-content-button')).toBeNull();
    });

    it('does not show "Add content" button when allowedTypes is empty object', () => {
      const column = makeColumn({ allowedTypes: {} });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByTestId('add-content-button')).toBeNull();
    });

    it('shows EmptyState when children is empty and allowedTypes is null', () => {
      const column = makeColumn({ children: [], allowedTypes: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByText('No content blocks')).toBeDefined();
    });

    it('does not show EmptyState when children is empty but allowedTypes has entries', () => {
      const column = makeColumn({
        children: [],
        allowedTypes: { 'App\\Model\\Text': { label: 'Text', icon: 'font-icon-block-content', description: '' } },
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByText('No content blocks')).toBeNull();
    });
  });
});
