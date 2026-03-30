import { describe, it, expect, vi } from 'vitest';
import { refreshPreview } from './refreshPreview';

describe('refreshPreview', () => {
  it('triggers aftersubmitform on the CMS edit form', () => {
    const trigger = vi.fn();
    window.jQuery = vi.fn().mockReturnValue({ length: 1, trigger }) as unknown as JQueryStatic;

    refreshPreview();

    expect(window.jQuery).toHaveBeenCalledWith('.cms-edit-form');
    expect(trigger).toHaveBeenCalledWith('aftersubmitform', {
      xhr: { getResponseHeader: expect.any(Function) },
    });
  });

  it('does nothing when jQuery is not available', () => {
    // @ts-expect-error — testing missing global
    delete window.jQuery;

    expect(() => refreshPreview()).not.toThrow();
  });

  it('does nothing when form element is not found', () => {
    window.jQuery = vi.fn().mockReturnValue({ length: 0 }) as unknown as JQueryStatic;

    expect(() => refreshPreview()).not.toThrow();
  });
});
