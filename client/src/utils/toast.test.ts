import { describe, it, expect, vi, beforeEach } from 'vitest';
import { showToast } from './toast';

beforeEach(() => {
  // Stub crypto.randomUUID for predictable toast IDs
  vi.spyOn(crypto, 'randomUUID').mockReturnValue('00000000-0000-0000-0000-000000000000');
});

describe('showToast', () => {
  it('dispatches DISPLAY_TOAST action when store is available', () => {
    const dispatch = vi.fn();
    window.ss!.store = { dispatch };

    showToast('Something failed', 'error');

    expect(dispatch).toHaveBeenCalledWith({
      type: 'DISPLAY_TOAST',
      payload: {
        id: 'toast-00000000-0000-0000-0000-000000000000',
        text: 'Something failed',
        type: 'error',
        stay: true,
      },
    });
  });

  it('sets stay to false for success toasts', () => {
    const dispatch = vi.fn();
    window.ss!.store = { dispatch };

    showToast('Done!', 'success');

    expect(dispatch).toHaveBeenCalledWith(
      expect.objectContaining({
        payload: expect.objectContaining({ stay: false }),
      }),
    );
  });

  it('defaults type to error', () => {
    const dispatch = vi.fn();
    window.ss!.store = { dispatch };

    showToast('Oops');

    expect(dispatch).toHaveBeenCalledWith(
      expect.objectContaining({
        payload: expect.objectContaining({ type: 'error' }),
      }),
    );
  });

  it('sets stay to true for warning toasts', () => {
    const dispatch = vi.fn();
    window.ss!.store = { dispatch };

    showToast('Heads up', 'warning');

    expect(dispatch).toHaveBeenCalledWith({
      type: 'DISPLAY_TOAST',
      payload: {
        id: 'toast-00000000-0000-0000-0000-000000000000',
        text: 'Heads up',
        type: 'warning',
        stay: true,
      },
    });
  });

  it('falls back to console.warn when store is unavailable', () => {
    delete window.ss!.store;
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

    showToast('No store', 'warning');

    expect(warnSpy).toHaveBeenCalledWith('[GridEditor] warning: No store');
  });
});
