import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ColumnBlock from '@/components/ColumnBlock/ColumnBlock';
import type { EnrichedColumnNode } from '@/types/enriched';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { getColumnCount, getOffsetStrategy, getOffsetOptions } from '@/utils/gridAdapter';

const { getIsOver, setIsOver } = vi.hoisted(() => {
  let value = false;
  return {
    getIsOver: () => value,
    setIsOver: (v: boolean) => { value = v; },
  };
});

const mockUpdateGridSettings = vi.fn().mockResolvedValue(undefined);

vi.mock('@/api/endpoints', () => ({
  createElement: vi.fn(),
  createContentElement: vi.fn(),
  archiveElement: vi.fn(),
  duplicateElement: vi.fn(),
  updateGridSettings: (...args: unknown[]) => mockUpdateGridSettings(...args),
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
  getOffsetStrategy: vi.fn(() => 'margin'),
  getDefaultViewport: vi.fn(() => 'md'),
  getWidthOptions: vi.fn(() => [
    ...Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: `${i + 1}/12` })),
    { value: 'hidden', label: 'hidden' },
  ]),
  getOffsetOptions: vi.fn((currentWidth?: number) => {
    const maxOffset = currentWidth !== undefined ? 12 - currentWidth : 11;
    return Array.from({ length: maxOffset + 1 }, (_, i) => ({ value: i, label: i === 0 ? 'none' : `+${i}` }));
  }),
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
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
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

  describe('layout: flex (margin offset strategy)', () => {
    beforeEach(() => {
      vi.mocked(getOffsetStrategy).mockReturnValue('margin');
    });

    it('sets --col-width as percentage', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 0, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%');
    });

    it('sets --col-offset when offset > 0', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 4, offset: 2, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      const expected = `${(2 / 12) * 100}%`;
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe(expected);
    });

    it('does not set --col-offset when offset is 0', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 0, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('');
    });
  });

  describe('layout: grid (grid-placement offset strategy)', () => {
    beforeEach(() => {
      vi.mocked(getOffsetStrategy).mockReturnValue('grid-placement');
    });

    it('sets --col-span as integer string', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 0, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-span')).toBe('6');
    });

    it('sets --col-start when offset > 0', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 4, offset: 2, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-start')).toBe('3');
    });

    it('does not set --col-start when offset is 0', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 0, visible: true } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-start')).toBe('');
    });
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
            icon: 'font-icon-block-content',
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
            icon: 'font-icon-block-content',
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
    const outerDiv = container.firstElementChild as HTMLElement;
    expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%');
  });

  it('cascades offset from smaller viewport', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 3, visible: true } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    const outerDiv = container.firstElementChild as HTMLElement;
    // lg inherits md offset=3 via cascade
    expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('25%');
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

  describe('edit link', () => {
    it('renders title as a link when editLink is present', () => {
      const column = makeColumn({
        title: 'Left Column',
        editLink: '/admin/pages/edit/EditForm/42/field/GridEditor/item/10/edit',
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const link = screen.getByTestId('column-edit-link');
      expect(link.tagName).toBe('A');
      expect(link.getAttribute('href')).toBe('/admin/pages/edit/EditForm/42/field/GridEditor/item/10/edit');
      expect(link.textContent).toBe('Left Column');
    });

    it('renders plain title text without link when editLink is null', () => {
      const column = makeColumn({ title: 'Left Column', editLink: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const titleEl = screen.getByTestId('column-title');
      expect(titleEl.textContent).toBe('Left Column');
      expect(screen.queryByTestId('column-edit-link')).toBeNull();
    });

    it('renders visible title text in header', () => {
      const column = makeColumn({ title: 'My Column' });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const header = screen.getByTestId('column-header');
      expect(header.textContent).toContain('My Column');
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

  it('passes archiveAction to ActionsMenu when canDelete is true', () => {
    const column = makeColumn({ canDelete: true });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const trigger = screen.getByTestId('actions-menu-trigger');
    expect(trigger).toBeDefined();
  });

  it('does not render ActionsMenu trigger when all actions are disabled', () => {
    const column = makeColumn({ canDelete: false, canCreate: false });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    // ActionsMenu returns null when actions array is empty
    expect(container.querySelector('.actions-menu')).toBeNull();
  });

  it('applies isDragging class when any drag is active', () => {
    const column = makeColumn();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'row') },
    );

    // When activeType is non-null, pickers should be disabled
    const widthPicker = screen.getByTestId('column-badge');
    expect(widthPicker.hasAttribute('disabled')).toBe(true);
  });

  it('disables pickers when drag is active (isDragActive)', () => {
    const column = makeColumn();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const widthPicker = screen.getByTestId('column-badge');
    expect(widthPicker.hasAttribute('disabled')).toBe(true);
    const offsetPicker = screen.getByTestId('column-offset-badge');
    expect(offsetPicker.hasAttribute('disabled')).toBe(true);
  });

  it('disables pickers when updateGridSettings mutation is pending', async () => {
    // With no drag active, pickers are enabled by default
    const column = makeColumn();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    // No drag active → pickers should be enabled
    const widthPicker = screen.getByTestId('column-badge');
    expect(widthPicker.hasAttribute('disabled')).toBe(false);
  });

  it('shows offset label "none" when offset is 0', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: true } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetBadge = screen.getByTestId('column-offset-badge');
    expect(offsetBadge.textContent).toBe('none');
  });

  it('shows offset label "+N" when offset is greater than 0', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 3, visible: true } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetBadge = screen.getByTestId('column-offset-badge');
    expect(offsetBadge.textContent).toBe('+3');
  });

  it('disables offset picker when column is full width', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 12, offset: 0, visible: true } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetPicker = screen.getByTestId('column-offset-badge');
    expect(offsetPicker.hasAttribute('disabled')).toBe(true);
  });

  it('disables offset picker when column is hidden', () => {
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 0, visible: false } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetPicker = screen.getByTestId('column-offset-badge');
    expect(offsetPicker.hasAttribute('disabled')).toBe(true);
  });

  it('clamps offset to maxOffset boundary (offset > maxOffset uses maxOffset)', async () => {
    // width=6, offset=6 → maxOffset = 12-6 = 6, offset=6 is exactly at boundary
    // Now select width=11, maxOffset=1, offset 6 > 1 → clamped to 1
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 6, visible: true } },
    });
    const user = userEvent.setup();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    await user.click(screen.getByTestId('column-badge'));
    const widthListbox = screen.getByTestId('column-badge-listbox');
    const width11Option = widthListbox.querySelector('[role="option"]:nth-child(11)');
    await user.click(width11Option!);

    const callArgs = mockUpdateGridSettings.mock.calls[0][0];
    expect(callArgs).toEqual(expect.objectContaining({
      width: 11,
      offset: 1,
      visible: true,
    }));
  });

  it('keeps offset at boundary when offset equals maxOffset exactly', async () => {
    // width=6, offset=6 → select width=6 again (maxOffset=6, offset=6 is NOT > 6)
    const column = makeColumn({
      gridSettings: { md: { width: 6, offset: 6, visible: true } },
    });
    const user = userEvent.setup();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    await user.click(screen.getByTestId('column-badge'));
    const widthListbox = screen.getByTestId('column-badge-listbox');
    // Select width=7, maxOffset=5, offset=6 > 5 → clamped to 5
    const width7Option = widthListbox.querySelector('[role="option"]:nth-child(7)');
    await user.click(width7Option!);

    const callArgs = mockUpdateGridSettings.mock.calls[0][0];
    expect(callArgs).toEqual(expect.objectContaining({
      width: 7,
      offset: 5,
      visible: true,
    }));
  });

  it('renders the block schema icon class on the icon element', () => {
    const column = makeColumn({
      blockSchema: {
        typeName: 'WeDevelop\\Grid\\Elements\\Column',
        label: 'Column',
        icon: 'font-icon-block-layout',
        type: 'Column',
        title: '',
        summary: '',
      },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const icon = container.querySelector('.column-block__icon.font-icon-block-layout');
    expect(icon).not.toBeNull();
    expect(icon?.tagName.toLowerCase()).toBe('i');
  });

  describe('archive dialog', () => {
    beforeEach(() => {
      HTMLDialogElement.prototype.showModal = vi.fn();
      HTMLDialogElement.prototype.close = vi.fn();
    });

    it('does not render ConfirmDialog when archive dialog is not open', () => {
      const column = makeColumn({ canDelete: true });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByTestId('confirm-dialog')).toBeNull();
    });

    it('renders ConfirmDialog when archive action is triggered', async () => {
      const column = makeColumn({ canDelete: true });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('actions-menu-trigger'));
      await user.click(screen.getByRole('menuitem', { name: 'Archive' }));

      expect(screen.getByTestId('confirm-dialog')).toBeDefined();
    });
  });

  it('renders ElementTypePicker when allowedTypes has entries', () => {
    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();

    const column = makeColumn({
      allowedTypes: { 'App\\Model\\Text': { label: 'Text', icon: 'font-icon-block-content', description: '' } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('element-type-picker')).toBeDefined();
  });

  it('does not render ElementTypePicker when allowedTypes is null', () => {
    const column = makeColumn({ allowedTypes: null });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.queryByTestId('element-type-picker')).toBeNull();
  });

  describe('offset options constrained by width', () => {
    it('calls getOffsetOptions with the resolved width', () => {
      const column = makeColumn({
        gridSettings: { md: { width: 8, offset: 0, visible: true } },
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(getOffsetOptions).toHaveBeenCalledWith(8);
    });

    it('shows only valid offset options in the dropdown', async () => {
      const column = makeColumn({
        gridSettings: { md: { width: 11, offset: 0, visible: true } },
      });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      // Open the offset picker
      await user.click(screen.getByTestId('column-offset-badge'));

      const listbox = screen.getByTestId('column-offset-badge-listbox');
      const options = listbox.querySelectorAll('[role="option"]');
      // Width=11 in a 12-column grid → max offset=1 → options: none, +1
      expect(options).toHaveLength(2);
    });

    it('auto-clamps offset when width change makes current offset invalid', async () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 5, visible: true } },
      });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      // Open width picker and select width=11
      await user.click(screen.getByTestId('column-badge'));
      const widthListbox = screen.getByTestId('column-badge-listbox');
      const width11Option = widthListbox.querySelector('[role="option"]:nth-child(11)');
      await user.click(width11Option!);

      // Width=11, columnCount=12, maxOffset=1, current offset=5 → clamp to 1
      const callArgs = mockUpdateGridSettings.mock.calls[0][0];
      expect(callArgs).toEqual(expect.objectContaining({
        width: 11,
        offset: 1,
        visible: true,
      }));
    });

    it('preserves offset when width change keeps it valid', async () => {
      const column = makeColumn({
        gridSettings: { md: { width: 6, offset: 2, visible: true } },
      });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      // Open width picker and select width=8 (maxOffset=4, current offset=2 is valid)
      await user.click(screen.getByTestId('column-badge'));
      const widthListbox = screen.getByTestId('column-badge-listbox');
      const width8Option = widthListbox.querySelector('[role="option"]:nth-child(8)');
      await user.click(width8Option!);

      const callArgs = mockUpdateGridSettings.mock.calls[0][0];
      expect(callArgs).toEqual(expect.objectContaining({
        width: 8,
        offset: 2,
        visible: true,
      }));
    });
  });
});
