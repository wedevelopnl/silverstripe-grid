import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ColumnBlock from '@/components/ColumnBlock/ColumnBlock';
import { createDndWrapper } from '@/tests/helpers/dndTestUtils';
import { makeEnrichedColumn } from '@/tests/helpers/enrichedFactories';
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
  duplicateToElement: vi.fn(),
  updateGridSettings: (...args: unknown[]) => mockUpdateGridSettings(...args),
  fetchPages: vi.fn().mockResolvedValue([]),
  fetchZones: vi.fn().mockResolvedValue([]),
  fetchAcceptableContainers: vi.fn().mockResolvedValue([]),
}));

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

vi.mock('@/utils/gridAdapter', async () => {
  const { MOCK_VIEWPORTS, resolveViewportSettingsImpl, defaultWidthOptions, defaultOffsetOptions } = await import('@/tests/helpers/mockData');
  return {
    getColumnCount: vi.fn(() => 12),
    getViewports: vi.fn(() => MOCK_VIEWPORTS),
    getOffsetStrategy: vi.fn(() => 'margin'),
    getDefaultViewport: vi.fn(() => 'md'),
    getWidthOptions: vi.fn(() => defaultWidthOptions()),
    getOffsetOptions: vi.fn((currentWidth?: number) => defaultOffsetOptions(currentWidth)),
    resolveViewportSettings: vi.fn(resolveViewportSettingsImpl),
  };
});

describe('ColumnBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    setIsOver(false);
    vi.mocked(getColumnCount).mockReturnValue(12);
    vi.mocked(getOffsetStrategy).mockReturnValue('margin');
  });

  it('renders fraction badge for the active viewport', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
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
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%');
    });

    it('sets --col-offset when offset > 0', () => {
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 4, offset: 2, visible: true } } },
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
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
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
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-span')).toBe('6');
    });

    it('sets --col-start when offset > 0', () => {
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 4, offset: 2, visible: true } } },
      });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const outerDiv = container.firstElementChild as HTMLElement;
      expect(outerDiv.style.getPropertyValue('--col-start')).toBe('3');
    });

    it('does not set --col-start when offset is 0', () => {
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
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
    const column = makeEnrichedColumn({
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
    const column = makeEnrichedColumn({ children: null });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No content blocks')).toBeDefined();
  });

  it('renders EmptyState when children is empty array', () => {
    const column = makeEnrichedColumn({ children: [] });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('No content blocks')).toBeDefined();
  });

  it('applies hidden modifier when visible is false', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: false } } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--hidden')).toBe(true);
  });

  it('does not apply hidden modifier when visible is true', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--hidden')).toBe(false);
  });

  it('shows "hidden" instead of fraction badge when not visible', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: false } } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByText('hidden')).toBeDefined();
    expect(screen.queryByText('6/12')).toBeNull();
  });

  it('applies publication state modifier class for draft column', () => {
    const column = makeEnrichedColumn({
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
    const column = makeEnrichedColumn({
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
    const column = makeEnrichedColumn({
      statusFlags: { modified: { text: 'Modified', title: 'Item has unpublished changes' } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--modified')).toBe(true);
  });

  it('uses override for the active viewport', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { lg: { width: 6, offset: 0, visible: true } } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    expect(screen.getByText('6/12')).toBeDefined();
    const outerDiv = container.firstElementChild as HTMLElement;
    expect(outerDiv.style.getPropertyValue('--col-width')).toBe('50%');
  });

  it('falls back to default when no override exists for viewport', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 3, visible: true } } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    const outerDiv = container.firstElementChild as HTMLElement;
    // lg has no override, falls back to default (width=12, offset=0)
    expect(screen.getByText('12/12')).toBeDefined();
    expect(outerDiv.style.getPropertyValue('--col-offset')).toBe('');
  });

  it('uses override with hidden visibility for active viewport', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { lg: { width: 6, offset: 0, visible: false } } },
    });

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('lg') },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--hidden')).toBe(true);
    expect(screen.getByText('hidden')).toBeDefined();
  });

  it('applies --drop-target modifier when isOver is true and activeType is column', () => {
    setIsOver(true);
    const column = makeEnrichedColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'column') },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(true);
  });

  it('does not apply --drop-target when isOver is true but activeType is not column', () => {
    setIsOver(true);
    const column = makeEnrichedColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'section') },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(false);
  });

  it('does not apply --drop-target modifier when isOver is false', () => {
    const column = makeEnrichedColumn();

    const { container } = render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const inner = container.querySelector('.column-block');
    expect(inner?.classList.contains('column-block--drop-target')).toBe(false);
  });

  describe('collapse', () => {
    it('renders a collapse toggle button', () => {
      const column = makeEnrichedColumn();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('collapse-toggle')).toBeDefined();
    });

    it('wires toggle to CollapseToggle onToggle', async () => {
      const toggle = vi.fn();
      const column = makeEnrichedColumn({ id: 55, toggle });
      const user = userEvent.setup();

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      await user.click(screen.getByTestId('collapse-toggle'));
      expect(toggle).toHaveBeenCalledOnce();
    });

    it('applies --collapsed modifier when collapsed', () => {
      const column = makeEnrichedColumn({ isCollapsed: true });

      const { container } = render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const inner = container.querySelector('.column-block');
      expect(inner?.classList.contains('column-block--collapsed')).toBe(true);
    });

    it('does not apply --collapsed modifier when expanded', () => {
      const column = makeEnrichedColumn({ isCollapsed: false });

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
      const column = makeEnrichedColumn({
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
      const column = makeEnrichedColumn({ title: 'Left Column', editLink: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const titleEl = screen.getByTestId('column-title');
      expect(titleEl.textContent).toBe('Left Column');
      expect(screen.queryByTestId('column-edit-link')).toBeNull();
    });

    it('renders visible title text in header', () => {
      const column = makeEnrichedColumn({ title: 'My Column' });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      const header = screen.getByTestId('column-header');
      expect(header.textContent).toContain('My Column');
    });
  });

  it('renders a drag handle', () => {
    const column = makeEnrichedColumn({ title: 'Left Column' });

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
      const column = makeEnrichedColumn({
        allowedTypes: { 'App\\Model\\Text': { label: 'Text', icon: 'font-icon-block-content', description: '' } },
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByTestId('add-content-button')).toBeDefined();
    });

    it('does not show "Add content" button when allowedTypes is null', () => {
      const column = makeEnrichedColumn({ allowedTypes: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByTestId('add-content-button')).toBeNull();
    });

    it('does not show "Add content" button when allowedTypes is empty object', () => {
      const column = makeEnrichedColumn({ allowedTypes: {} });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.queryByTestId('add-content-button')).toBeNull();
    });

    it('shows EmptyState when children is empty and allowedTypes is null', () => {
      const column = makeEnrichedColumn({ children: [], allowedTypes: null });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(screen.getByText('No content blocks')).toBeDefined();
    });

    it('does not show EmptyState when children is empty but allowedTypes has entries', () => {
      const column = makeEnrichedColumn({
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

  it('renders ElementActions with actions menu', () => {
    const column = makeEnrichedColumn({ canDelete: true });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('actions-menu-trigger')).toBeDefined();
  });

  it('applies isDragging class when any drag is active', () => {
    const column = makeEnrichedColumn();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper('md', [], 'row') },
    );

    // When activeType is non-null, pickers should be disabled
    const widthPicker = screen.getByTestId('column-badge');
    expect(widthPicker.hasAttribute('disabled')).toBe(true);
  });

  it('disables pickers when drag is active (isDragActive)', () => {
    const column = makeEnrichedColumn();

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
    const column = makeEnrichedColumn();

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    // No drag active → pickers should be enabled
    const widthPicker = screen.getByTestId('column-badge');
    expect(widthPicker.hasAttribute('disabled')).toBe(false);
  });

  it('shows offset label "none" when offset is 0', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: true } } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetBadge = screen.getByTestId('column-offset-badge');
    expect(offsetBadge.textContent).toBe('none');
  });

  it('shows offset label "+N" when offset is greater than 0', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 3, visible: true } } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetBadge = screen.getByTestId('column-offset-badge');
    expect(offsetBadge.textContent).toBe('+3');
  });

  it('disables offset picker when column is full width', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 12, offset: 0, visible: true } } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    const offsetPicker = screen.getByTestId('column-offset-badge');
    expect(offsetPicker.hasAttribute('disabled')).toBe(true);
  });

  it('disables offset picker when column is hidden', () => {
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 0, visible: false } } },
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
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 6, visible: true } } },
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
    const column = makeEnrichedColumn({
      gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 6, visible: true } } },
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
    const column = makeEnrichedColumn({
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

  it('renders ElementTypePicker when allowedTypes has entries', () => {
    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();

    const column = makeEnrichedColumn({
      allowedTypes: { 'App\\Model\\Text': { label: 'Text', icon: 'font-icon-block-content', description: '' } },
    });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.getByTestId('element-type-picker')).toBeDefined();
  });

  it('does not render ElementTypePicker when allowedTypes is null', () => {
    const column = makeEnrichedColumn({ allowedTypes: null });

    render(
      <ColumnBlock column={column} />,
      { wrapper: createDndWrapper() },
    );

    expect(screen.queryByTestId('element-type-picker')).toBeNull();
  });

  describe('offset options constrained by width', () => {
    it('calls getOffsetOptions with the resolved width', () => {
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 8, offset: 0, visible: true } } },
      });

      render(
        <ColumnBlock column={column} />,
        { wrapper: createDndWrapper() },
      );

      expect(getOffsetOptions).toHaveBeenCalledWith(8);
    });

    it('shows only valid offset options in the dropdown', async () => {
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 11, offset: 0, visible: true } } },
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
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 5, visible: true } } },
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
      const column = makeEnrichedColumn({
        gridSettings: { default: { width: 12, offset: 0, visible: true }, overrides: { md: { width: 6, offset: 2, visible: true } } },
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
