import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useDuplicateAction } from './useDuplicateAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createSimpleElement } from '@/testing/factories';
import { mockFetchSuccess } from '@/testing/mockFetch';

describe('useDuplicateAction', () => {
  it('should return null action when canCreate is false', () => {
    const node = createSimpleElement({ canCreate: false });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateAction(node), { wrapper });

    expect(result.current.action).toBeNull();
  });

  it('should return action with key "duplicate" when canCreate is true', () => {
    const node = createSimpleElement({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateAction(node), { wrapper });

    expect(result.current.action).not.toBeNull();
    expect(result.current.action?.key).toBe('duplicate');
    expect(result.current.action?.label).toBe('Duplicate');
  });

  it('should call duplicate mutation when onAction is invoked', async () => {
    mockFetchSuccess({});

    const node = createSimpleElement({ id: 77, canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateAction(node), { wrapper });

    act(() => {
      result.current.action?.onAction();
    });

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
    });

    const [url] = vi.mocked(globalThis.fetch).mock.calls[0];
    expect(String(url)).toContain('duplicate');
  });
});
