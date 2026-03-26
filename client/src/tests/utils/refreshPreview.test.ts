import { refreshPreview } from '@/utils/refreshPreview';

const mockTrigger = vi.fn();
const mockJQuery = vi.fn();

beforeEach(() => {
  mockTrigger.mockReset();
  mockJQuery.mockReset();

  mockJQuery.mockReturnValue({ length: 1, trigger: mockTrigger });
  window.jQuery = mockJQuery as any;
});

afterEach(() => {
  delete (window as any).jQuery;
});

describe('refreshPreview', () => {
  it('triggers aftersubmitform on the CMS edit form', () => {
    refreshPreview();

    expect(mockJQuery).toHaveBeenCalledWith('.cms-edit-form');
    expect(mockTrigger).toHaveBeenCalledWith('aftersubmitform', {
      xhr: { getResponseHeader: expect.any(Function) },
    });
  });

  it('provides a dummy xhr whose getResponseHeader returns null', () => {
    refreshPreview();

    const eventData = mockTrigger.mock.calls[0][1] as { xhr: { getResponseHeader: (name: string) => null } };
    expect(eventData.xhr.getResponseHeader('X-Controller')).toBeNull();
  });

  it('does not trigger when CMS edit form is absent', () => {
    mockJQuery.mockReturnValue({ length: 0 });

    refreshPreview();

    expect(mockTrigger).not.toHaveBeenCalled();
  });

  it('does not throw when jQuery is unavailable', () => {
    delete (window as any).jQuery;

    expect(() => refreshPreview()).not.toThrow();
  });
});
