import {
  createContentElement,
  createElement,
  archiveElement,
  duplicateElement,
  duplicateToElement,
  fetchAcceptableContainers,
  fetchElementTree,
  fetchPages,
  fetchZones,
  publishElement,
  reorderElement,
  unpublishElement,
  updateGridSettings,
} from '@/api/endpoints';

const mockApiGet = vi.fn();
const mockApiPost = vi.fn();
const mockApiPatch = vi.fn();
const mockApiDelete = vi.fn();

vi.mock('@/api/client', () => ({
  apiGet: (...args: unknown[]) => mockApiGet(...args),
  apiPost: (...args: unknown[]) => mockApiPost(...args),
  apiPatch: (...args: unknown[]) => mockApiPatch(...args),
  apiDelete: (...args: unknown[]) => mockApiDelete(...args),
}));

vi.mock('@/api/config', () => ({
  getControllerLink: () => '/admin/grid',
}));

describe('endpoints', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    mockApiGet.mockReset();
    mockApiPost.mockReset();
    mockApiPatch.mockReset();
    mockApiDelete.mockReset();
  });

  describe('fetchElementTree', () => {
    it('calls GET with correct URL and validates response', async () => {
      const mockTree = {
        '42': [
          {
            id: 1,
            parentId: 1,
            title: 'Section',
            containerType: 'section',
            allowedTypes: null,
            children: null,
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
      mockApiGet.mockResolvedValue(mockTree);

      const result = await fetchElementTree(42, 'main');

      expect(mockApiGet).toHaveBeenCalledWith(
        '/admin/grid/api/readTree/42/main',
      );
      expect(result).toEqual(mockTree);
    });

    it('returns raw response without runtime validation', async () => {
      mockApiGet.mockResolvedValue('not-an-object');

      const result = await fetchElementTree(1, 'main');
      expect(result).toBe('not-an-object');
    });
  });

  describe('createElement', () => {
    it('sends correct POST body', async () => {
      mockApiPost.mockResolvedValue(undefined);

      await createElement({
        containerType: 'section',
        parentId: 10,
        insertAfterElementID: 5,
      });

      expect(mockApiPost).toHaveBeenCalledWith(
        '/admin/grid/api/create',
        {
          containerType: 'section',
          parentId: 10,
          insertAfterElementID: 5,
        },
      );
    });
  });

  describe('publishElement', () => {
    it('sends correct PATCH body', async () => {
      mockApiPatch.mockResolvedValue(undefined);

      await publishElement(7);

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/admin/grid/api/publish',
        { id: 7 },
      );
    });
  });

  describe('unpublishElement', () => {
    it('sends correct PATCH body', async () => {
      mockApiPatch.mockResolvedValue(undefined);

      await unpublishElement(7);

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/admin/grid/api/unpublish',
        { id: 7 },
      );
    });
  });

  describe('archiveElement', () => {
    it('sends correct DELETE body', async () => {
      mockApiDelete.mockResolvedValue(undefined);

      await archiveElement(3);

      expect(mockApiDelete).toHaveBeenCalledWith(
        '/admin/grid/api/delete',
        { id: 3 },
      );
    });
  });

  describe('duplicateElement', () => {
    it('sends correct POST body', async () => {
      mockApiPost.mockResolvedValue(undefined);

      await duplicateElement(9);

      expect(mockApiPost).toHaveBeenCalledWith(
        '/admin/grid/api/duplicate',
        { id: 9 },
      );
    });
  });

  describe('createContentElement', () => {
    it('sends correct POST body', async () => {
      mockApiPost.mockResolvedValue(undefined);

      await createContentElement({
        className: 'SilverStripe\\ElementalBlocks\\Block\\ContentBlock',
        parentId: 10,
        insertAfterElementID: 5,
      });

      expect(mockApiPost).toHaveBeenCalledWith(
        '/admin/grid/api/createContent',
        {
          className: 'SilverStripe\\ElementalBlocks\\Block\\ContentBlock',
          parentId: 10,
          insertAfterElementID: 5,
        },
      );
    });
  });

  describe('updateGridSettings', () => {
    it('sends correct PATCH body', async () => {
      mockApiPatch.mockResolvedValue(undefined);

      await updateGridSettings({
        id: 4,
        viewport: 'md',
        width: 6,
        offset: 1,
        visible: true,
      });

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/admin/grid/api/updateGridSettings',
        {
          id: 4,
          viewport: 'md',
          width: 6,
          offset: 1,
          visible: true,
        },
      );
    });
  });

  describe('reorderElement', () => {
    it('sends correct PATCH body with afterElementID', async () => {
      mockApiPatch.mockResolvedValue(undefined);

      await reorderElement({
        elementID: 5,
        targetParentId: 10,
        afterElementID: 3,
      });

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/admin/grid/api/reorder',
        {
          elementID: 5,
          targetParentId: 10,
          afterElementID: 3,
        },
      );
    });

    it('sends null afterElementID for first position', async () => {
      mockApiPatch.mockResolvedValue(undefined);

      await reorderElement({
        elementID: 5,
        targetParentId: 10,
        afterElementID: null,
      });

      expect(mockApiPatch).toHaveBeenCalledWith(
        '/admin/grid/api/reorder',
        {
          elementID: 5,
          targetParentId: 10,
          afterElementID: null,
        },
      );
    });
  });

  describe('fetchPages', () => {
    it('calls GET without query string when no search provided', async () => {
      mockApiGet.mockResolvedValue([]);

      await fetchPages();

      expect(mockApiGet).toHaveBeenCalledWith('/admin/grid/api/pages');
    });

    it('calls GET with encoded search query string', async () => {
      mockApiGet.mockResolvedValue([]);

      await fetchPages('hello world');

      expect(mockApiGet).toHaveBeenCalledWith(
        '/admin/grid/api/pages?search=hello%20world',
      );
    });

    it('does not include search param when called without argument', async () => {
      mockApiGet.mockResolvedValue([]);

      await fetchPages();

      const url = mockApiGet.mock.calls[0][0] as string;
      expect(url).not.toContain('?search=');
    });
  });

  describe('fetchZones', () => {
    it('calls GET with page ID in URL', async () => {
      mockApiGet.mockResolvedValue(['main']);

      await fetchZones(42);

      expect(mockApiGet).toHaveBeenCalledWith('/admin/grid/api/zones/42');
    });
  });

  describe('fetchAcceptableContainers', () => {
    it('calls GET with page ID, zone, and element type in URL', async () => {
      mockApiGet.mockResolvedValue([]);

      await fetchAcceptableContainers(42, 'main', 'row');

      expect(mockApiGet).toHaveBeenCalledWith(
        '/admin/grid/api/acceptableContainers/42/main/row',
      );
    });
  });

  describe('duplicateToElement', () => {
    it('sends correct POST body', async () => {
      mockApiPost.mockResolvedValue(undefined);

      await duplicateToElement({
        id: 1,
        targetPageId: 2,
        targetZone: 'main',
        targetParentId: 3,
      });

      expect(mockApiPost).toHaveBeenCalledWith(
        '/admin/grid/api/duplicateTo',
        {
          id: 1,
          targetPageId: 2,
          targetZone: 'main',
          targetParentId: 3,
        },
      );
    });
  });
});
