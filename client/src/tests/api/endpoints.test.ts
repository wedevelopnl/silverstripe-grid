import {
  duplicateToElement,
  fetchPages,
  reorderElement,
} from '@/api/endpoints';

const mockApiGet = vi.fn();
const mockApiPost = vi.fn();
const mockApiPatch = vi.fn();

vi.mock('@/api/client', () => ({
  apiGet: (...args: unknown[]) => mockApiGet(...args),
  apiPost: (...args: unknown[]) => mockApiPost(...args),
  apiPatch: (...args: unknown[]) => mockApiPatch(...args),
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
