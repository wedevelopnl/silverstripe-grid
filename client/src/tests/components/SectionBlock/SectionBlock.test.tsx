import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import SectionBlock from '@/components/SectionBlock/SectionBlock';
import type { EnrichedSectionNode, EnrichedRowNode } from '@/types/enriched';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import {
  getOffsetStrategy,
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
  getRowClasses: vi.fn(() => 'row'),
  getOffsetStrategy: vi.fn(() => 'margin'),
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

function makeRow(id: number, title: string, overrides: Partial<EnrichedRowNode> = {}): EnrichedRowNode {
  return {
    id,
    parentId: 300,
    title,
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
    children: null,
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `row-${id}`,
    childSortableIds: [],
    ...overrides,
  };
}

function makeSection(overrides: Partial<EnrichedSectionNode> = {}): EnrichedSectionNode {
  const id = overrides.id ?? 1;
  const children = overrides.children ?? null;
  return {
    id,
    parentId: 42,
    title: 'Section',
    blockSchema: {
      typeName: 'WeDevelop\\Grid\\Elements\\Section',
      label: 'Section',
      icon: 'font-icon-block-content',
      type: 'Section',
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
    containerType: 'section',
    allowedTypes: null,
    children,
    isCollapsed: false,
    toggle: vi.fn(),
    sortableId: `section-${id}`,
    childSortableIds: children?.map((c) => c.sortableId) ?? [],
    ...overrides,
  };
}

describe('SectionBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setIsOver(false);
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
  });

  it('renders as a <section> element', () => {
    const section = makeSection();

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    expect(container.querySelector('section.section-block')).not.toBeNull();
  });

  it('renders title as an h2 heading', () => {
    const section = makeSection({ title: 'Hero Section' });

    render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const heading = screen.getByRole('heading', { level: 2 });
    expect(heading.textContent).toBe('Hero Section');
  });

  it('renders row children as RowBlocks', () => {
    const section = makeSection({
      children: [
        makeRow(10, 'First Row'),
        makeRow(11, 'Second Row'),
      ],
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const rowBlocks = container.querySelectorAll('.row-block');
    expect(rowBlocks.length).toBe(2);
  });

  it('renders add child empty state when children is null', () => {
    const section = makeSection({ children: null });

    render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No rows yet')).toBeDefined();
    expect(screen.getByText('Add Row')).toBeDefined();
  });

  it('renders add child empty state when children is empty array', () => {
    const section = makeSection({ children: [] });

    render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No rows yet')).toBeDefined();
    expect(screen.getByText('Add Row')).toBeDefined();
  });

  it('applies draft publication state modifier class', () => {
    const section = makeSection({
      statusFlags: { addedtodraft: { text: 'Draft', title: 'Item has not been published yet' } },
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--draft')).toBe(true);
  });

  it('applies published publication state modifier class', () => {
    const section = makeSection({
      statusFlags: {},
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--published')).toBe(true);
  });

  it('applies modified publication state modifier class', () => {
    const section = makeSection({
      statusFlags: { modified: { text: 'Modified', title: 'Item has unpublished changes' } },
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--modified')).toBe(true);
  });

  it('child columns resolve settings based on activeViewport from context', () => {
    const section = makeSection({
      children: [makeRow(10, 'Row')],
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper('lg') },
    );

    const rowBlocks = container.querySelectorAll('.row-block');
    expect(rowBlocks.length).toBe(1);
  });

  it('rows apply flex layout class from offsetStrategy', () => {
    const section = makeSection({
      children: [makeRow(10, 'Row')],
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const columnContainer = container.querySelector('.row-block__columns--flex');
    expect(columnContainer).not.toBeNull();
  });

  it('nested columns set CSS custom properties for layout', () => {
    const section = makeSection({
      children: [
        makeRow(10, 'Row', {
          children: [
            {
              id: 30,
              parentId: 200,
              title: 'Column',
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
                overrides: { md: { width: 8, offset: 2, visible: true } },
              },
              isCollapsed: false,
              toggle: vi.fn(),
              sortableId: 'column-30',
              childSortableIds: [],
            },
          ],
        }),
      ],
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const columnOuter = container.querySelector('.row-block__column') as HTMLElement;
    expect(columnOuter.style.getPropertyValue('--col-width')).toBe(`${(8 / 12) * 100}%`);
    expect(columnOuter.style.getPropertyValue('--col-offset')).toBe(`${(2 / 12) * 100}%`);
  });

  it('renders rows in the body area within section-block__body', () => {
    const section = makeSection({
      children: [makeRow(10, 'First Row')],
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const body = container.querySelector('.section-block__body');
    expect(body).not.toBeNull();

    const rowsInsideBody = body?.querySelectorAll('.row-block');
    expect(rowsInsideBody?.length).toBe(1);
  });

  it('applies --drop-target modifier when isOver is true and activeType is section', () => {
    setIsOver(true);
    const section = makeSection();

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--drop-target')).toBe(true);
  });

  it('does not apply --drop-target when isOver is true but activeType is not section', () => {
    setIsOver(true);
    const section = makeSection();

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper('md', [], 'row') },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--drop-target')).toBe(false);
  });

  it('does not apply --drop-target modifier when isOver is false', () => {
    const section = makeSection();

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const outer = container.querySelector('.section-block');
    expect(outer?.classList.contains('section-block--drop-target')).toBe(false);
  });

  describe('collapse', () => {
    it('renders a collapse toggle button', () => {
      const section = makeSection();

      render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('collapse-toggle')).toBeDefined();
    });

    it('wires toggle to CollapseToggle onToggle', async () => {
      const toggle = vi.fn();
      const section = makeSection({ toggle });
      const user = userEvent.setup();

      render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('collapse-toggle'));
      expect(toggle).toHaveBeenCalledOnce();
    });

    it('hides body when collapsed', () => {
      const section = makeSection({
        isCollapsed: true,
        children: [makeRow(10, 'First Row')],
      });

      const { container } = render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.section-block');
      expect(outer?.classList.contains('section-block--collapsed')).toBe(true);
    });

    it('shows body when expanded', () => {
      const section = makeSection({
        isCollapsed: false,
        children: [makeRow(10, 'First Row')],
      });

      const { container } = render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      const outer = container.querySelector('.section-block');
      expect(outer?.classList.contains('section-block--collapsed')).toBe(false);
    });
  });

  it('renders ElementActions with actions menu', () => {
    const section = makeSection({ canDelete: true });

    render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  it('renders the block schema icon class on the icon element', () => {
    const section = makeSection({
      blockSchema: {
        typeName: 'WeDevelop\\Grid\\Elements\\Section',
        label: 'Section',
        icon: 'font-icon-block-layout',
        type: 'Section',
        title: '',
        summary: '',
      },
    });

    const { container } = render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const icon = container.querySelector('.section-block__icon.font-icon-block-layout');
    expect(icon).not.toBeNull();
    expect(icon?.tagName.toLowerCase()).toBe('i');
  });

  describe('edit link', () => {
    it('renders title as a link when editLink is present', () => {
      const section = makeSection({
        title: 'Hero Section',
        editLink: '/admin/pages/edit/EditForm/42/field/GridEditor/item/1/edit',
      });

      render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      const link = screen.getByTestId('section-edit-link');
      expect(link.tagName).toBe('A');
      expect(link.getAttribute('href')).toBe('/admin/pages/edit/EditForm/42/field/GridEditor/item/1/edit');
      expect(link.textContent).toBe('Hero Section');
    });

    it('renders plain heading without link when editLink is null', () => {
      const section = makeSection({ title: 'Hero Section', editLink: null });

      render(
        <SectionBlock section={section} />,
        { wrapper: createDndWrapper() },
      );

      const heading = screen.getByRole('heading', { level: 2 });
      expect(heading.textContent).toBe('Hero Section');
      expect(screen.queryByTestId('section-edit-link')).toBeNull();
    });
  });

  it('renders a drag handle', () => {
    const section = makeSection({ title: 'Hero Section' });

    render(
      <SectionBlock section={section} />,
      { wrapper: createDndWrapper() },
    );

    const handle = screen.getByTestId('drag-handle');
    expect(handle).toBeDefined();
    expect(handle.getAttribute('aria-label')).toBe('Move Hero Section');
  });
});
