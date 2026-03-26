import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import GridEditor from '@/components/GridEditor/GridEditor';
import type { ElementTreeResponse } from '@/types/elements';
import { createQueryWrapper } from '../../helpers/dndTestUtils';

const mockFetchElementTree = vi.fn();

const mockCreateElement = vi.fn();

vi.mock('@/api/endpoints', () => ({
  fetchElementTree: (...args: unknown[]) => mockFetchElementTree(...args),
  createElement: (...args: unknown[]) => mockCreateElement(...args),
  createContentElement: vi.fn(),
  archiveElement: vi.fn(),
  duplicateElement: vi.fn(),
  duplicateToElement: vi.fn(),
  reorderElement: vi.fn(),
  updateGridSettings: vi.fn(),
  fetchPages: vi.fn().mockResolvedValue([]),
  fetchZones: vi.fn().mockResolvedValue([]),
  fetchAcceptableContainers: vi.fn().mockResolvedValue([]),
}));

vi.mock('@/utils/gridAdapter', async () => {
  const { MOCK_VIEWPORTS, resolveViewportSettingsImpl, defaultWidthOptions, defaultOffsetOptions } = await import('@/tests/helpers/mockData');
  return {
    getViewports: vi.fn(() => MOCK_VIEWPORTS),
    getDefaultViewport: vi.fn(() => 'md'),
    getColumnCount: vi.fn(() => 12),
    getRowClasses: vi.fn(() => 'row'),
    getOffsetStrategy: vi.fn(() => 'margin'),
    getWidthClass: vi.fn((width: number) => `col-${width}`),
    getOffsetClass: vi.fn((offset: number) => `offset-${offset}`),
    getWidthOptions: vi.fn(() => defaultWidthOptions()),
    getOffsetOptions: vi.fn(() => defaultOffsetOptions()),
    resolveViewportSettings: vi.fn(resolveViewportSettingsImpl),
  };
});

const mockTree: ElementTreeResponse = {
  '7': [
    {
      id: 1,
      parentId: 7,
      title: 'Main Section',
      containerType: 'section',
      allowedTypes: null,
      children: [
        {
          id: 2,
          parentId: 1,
          title: 'First Row',
          containerType: 'row',
          allowedTypes: null,
          children: [
            {
              id: 3,
              parentId: 2,
              title: 'Left Column',
              containerType: 'column',
              allowedTypes: null,
              children: [
                {
                  id: 4,
                  parentId: 3,
                  title: 'Text Block',
                  blockSchema: { typeName: 'Content', label: 'Content', icon: 'font-icon-block-content', type: 'Content', title: '', summary: '' },
                  obsoleteClassName: null,
                  version: 1,
                  canDelete: true,
                  canPublish: true,
                  canUnpublish: false,
                  canCreate: true,
                  editLink: null,
                  statusFlags: {},
                },
              ],
              gridSettings: {
                default: { width: 8, offset: 0, visible: true },
                overrides: { lg: { width: 6, offset: 0, visible: true } },
              },
              blockSchema: { typeName: 'Column', label: 'Column', icon: 'font-icon-block-content', type: 'Column', title: '', summary: '' },
              obsoleteClassName: null,
              version: 1,

              canDelete: true,
              canPublish: true,
              canUnpublish: false,
              canCreate: true,
              editLink: null,
              statusFlags: {},
            },
            {
              id: 5,
              parentId: 2,
              title: 'Right Column',
              containerType: 'column',
              allowedTypes: null,
              children: null,
              gridSettings: {
                default: { width: 4, offset: 0, visible: true },
                overrides: { lg: { width: 6, offset: 0, visible: true } },
              },
              blockSchema: { typeName: 'Column', label: 'Column', icon: 'font-icon-block-content', type: 'Column', title: '', summary: '' },
              obsoleteClassName: null,
              version: 1,

              canDelete: true,
              canPublish: true,
              canUnpublish: false,
              canCreate: true,
              editLink: null,
              statusFlags: {},
            },
          ],
          blockSchema: { typeName: 'Row', label: 'Row', icon: 'font-icon-block-content', type: 'Row', title: '', summary: '' },
          obsoleteClassName: null,
          version: 1,
          canDelete: true,
          canPublish: true,
          canUnpublish: false,
          canCreate: true,
          editLink: null,
          statusFlags: {},
        },
      ],
      blockSchema: { typeName: 'Section', label: 'Section', icon: 'font-icon-block-content', type: 'Section', title: '', summary: '' },
      obsoleteClassName: null,
      version: 1,
      canDelete: true,
      canPublish: true,
      canUnpublish: false,
      canCreate: true,
      editLink: null,
      statusFlags: {},
    },
  ],
};

const emptyTree: ElementTreeResponse = {
  '42': [],
};

const noSectionsTree: ElementTreeResponse = {
  '7': [
    {
      id: 99,
      parentId: 7,
      title: 'Standalone Block',
      blockSchema: { typeName: 'Content', label: 'Content', icon: 'font-icon-block-content', type: 'Content', title: '', summary: '' },
      obsoleteClassName: null,
      version: 1,
      canDelete: true,
      canPublish: true,
      canUnpublish: false,
      canCreate: true,
      editLink: null,
      statusFlags: {},
    },
  ],
};

describe('GridEditor', () => {
  afterEach(() => {
    mockFetchElementTree.mockReset();
    mockCreateElement.mockReset();
  });

  it('shows loading state when fetching', () => {
    mockFetchElementTree.mockReturnValue(new Promise(() => {}));

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    expect(screen.getByText('Loading elements...')).toBeDefined();
  });

  it('shows error message when fetch fails', async () => {
    mockFetchElementTree.mockRejectedValue(new Error('Network error'));

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByText(/Failed to load elements/)).toBeDefined();
    });
  });

  it('renders viewport switcher when data loads', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByRole('group', { name: 'Viewport size' })).toBeDefined();
    });

    // All viewport buttons are rendered
    expect(screen.getByText('XS')).toBeDefined();
    expect(screen.getByText('SM')).toBeDefined();
    expect(screen.getByText('MD')).toBeDefined();
    expect(screen.getByText('LG')).toBeDefined();
    expect(screen.getByText('XL')).toBeDefined();
    expect(screen.getByText('XXL')).toBeDefined();
  });

  it('renders section blocks when data loads', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByText('Main Section')).toBeDefined();
    });

    const sectionBlocks = screen.getAllByTestId('section-block');
    expect(sectionBlocks.length).toBe(1);
  });

  it('renders add child empty state when tree has no sections (empty relation)', async () => {
    mockFetchElementTree.mockResolvedValue(emptyTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByTestId('add-child-empty')).toBeDefined();
    });

    expect(screen.getByText('No sections yet')).toBeDefined();
    expect(screen.getByTestId('add-child-button')).toBeDefined();
  });

  it('renders add child empty state when tree has nodes but none are sections', async () => {
    mockFetchElementTree.mockResolvedValue(noSectionsTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByTestId('add-child-empty')).toBeDefined();
    });

    expect(screen.getByTestId('add-child-button')).toBeDefined();
  });

  it('sets data-page-id attribute from pageId prop', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    const editorDiv = screen.getByTestId('grid-editor');
    expect(editorDiv.dataset.pageId).toBe('7');
  });

  it('sets data-page-id attribute when pageId is provided', () => {
    mockFetchElementTree.mockReturnValue(new Promise(() => {}));

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    const editorDiv = screen.getByTestId('grid-editor');
    expect(editorDiv.dataset.pageId).toBe('7');
  });

  it('omits data-page-id attribute when pageId is null', () => {
    render(<GridEditor pageId={null} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    const editorDiv = screen.getByTestId('grid-editor');
    expect(editorDiv.dataset.pageId).toBeUndefined();
  });

  it('updates column fraction badges when viewport is switched', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);
    const user = userEvent.setup();

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    // Wait for data to load — default viewport is "md"
    await waitFor(() => {
      expect(screen.getByText('Main Section')).toBeDefined();
    });

    // At md viewport: Left Column = 8/12, Right Column = 4/12
    expect(screen.getByText('8/12')).toBeDefined();
    expect(screen.getByText('4/12')).toBeDefined();

    // Switch to lg viewport
    await user.click(screen.getByText('LG'));

    // At lg viewport: Left Column = 6/12, Right Column = 6/12
    const badges = screen.getAllByText('6/12');
    expect(badges.length).toBe(2);
  });

  it('renders add child empty state when pageId is not present in the response', async () => {
    const treeForDifferentArea: ElementTreeResponse = {
      '99': [mockTree['7'][0]],
    };
    mockFetchElementTree.mockResolvedValue(treeForDifferentArea);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByTestId('add-child-empty')).toBeDefined();
    });
  });

  it('does not render content area when still loading', () => {
    mockFetchElementTree.mockReturnValue(new Promise(() => {}));

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    expect(screen.queryByTestId('section-block')).toBeNull();
    expect(screen.queryByTestId('viewport-switcher')).toBeNull();
    expect(screen.queryByTestId('add-child-empty')).toBeNull();
    expect(screen.queryByTestId('add-child-button')).toBeNull();
  });

  it('renders add section append button after existing sections', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByText('Main Section')).toBeDefined();
    });

    expect(screen.getByText('Add Section')).toBeDefined();
  });

  it('calls createElement with correct params when add section is clicked', async () => {
    mockFetchElementTree.mockResolvedValue(emptyTree);
    mockCreateElement.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByTestId('add-child-empty')).toBeDefined();
    });

    await user.click(screen.getByText('Add Section'));

    expect(mockCreateElement).toHaveBeenCalledWith(
      {
        containerType: 'section',
        parentId: 7,
        zone: 'main',
      },
      expect.anything(),
    );
  });

  it('does not render DragOverlayContent when no drag is active', async () => {
    mockFetchElementTree.mockResolvedValue(mockTree);

    render(<GridEditor pageId={7} zone="main" />, {
      wrapper: createQueryWrapper().wrapper,
    });

    await waitFor(() => {
      expect(screen.getByText('Main Section')).toBeDefined();
    });

    expect(screen.queryByTestId('drag-overlay-section')).toBeNull();
    expect(screen.queryByTestId('drag-overlay-row')).toBeNull();
    expect(screen.queryByTestId('drag-overlay-column')).toBeNull();
    expect(screen.queryByTestId('drag-overlay-element')).toBeNull();
  });
});
