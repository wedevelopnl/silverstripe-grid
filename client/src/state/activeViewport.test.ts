import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  MOBILE_FIRST_PREVIEW_WIDTH,
  getActiveViewport,
  resetActiveViewportStore,
  setActiveViewport,
  subscribeActiveViewport,
} from './activeViewport';

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
  ],
}));

describe('activeViewport store', () => {
  afterEach(() => {
    resetActiveViewportStore();
  });

  it('exports the mobile-first preview width constant', () => {
    expect(MOBILE_FIRST_PREVIEW_WIDTH).toBe(375);
  });

  it('lazily initialises to the adapter default', () => {
    expect(getActiveViewport()).toBe('md');
  });

  it('updates and notifies subscribers on setActiveViewport', () => {
    const listener = vi.fn();
    const unsubscribe = subscribeActiveViewport(listener);

    setActiveViewport('sm');

    expect(getActiveViewport()).toBe('sm');
    expect(listener).toHaveBeenCalledTimes(1);

    unsubscribe();
  });

  it('does not notify subscribers when value is unchanged', () => {
    setActiveViewport('md');
    const listener = vi.fn();
    const unsubscribe = subscribeActiveViewport(listener);

    setActiveViewport('md');

    expect(listener).not.toHaveBeenCalled();
    unsubscribe();
  });

  it('stops notifying after unsubscribe', () => {
    const listener = vi.fn();
    const unsubscribe = subscribeActiveViewport(listener);
    unsubscribe();

    setActiveViewport('sm');

    expect(listener).not.toHaveBeenCalled();
  });
});
