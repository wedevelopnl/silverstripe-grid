import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useArchiveAction } from './useArchiveAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createSectionNode, createSimpleElement } from '@/testing/factories';
import { mockFetchSuccess } from '@/testing/mockFetch';

describe('useArchiveAction', () => {
  it('should return null action when canDelete is false', () => {
    const node = createSimpleElement({ canDelete: false });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(node), { wrapper });

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('should return action with key "archive" when canDelete is true', () => {
    const node = createSimpleElement({ canDelete: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(node), { wrapper });

    expect(result.current.action).not.toBeNull();
    expect(result.current.action?.key).toBe('archive');
    expect(result.current.action?.destructive).toBe(true);
  });

  it('should include descendant count in dialog message', () => {
    const section = createSectionNode({
      title: 'My Section',
      canDelete: true,
    });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(section), { wrapper });

    // Section has 1 row with 1 column with 1 element = 3 descendants
    expect(result.current.dialog?.message).toBe(
      'Archive "My Section" and all 3 child elements?',
    );
  });

  it('should use singular "child element" for 1 descendant', () => {
    const section = createSectionNode({
      title: 'Tiny Section',
      canDelete: true,
      children: [
        // A single row with no columns gives 1 descendant
        {
          id: 50,
          parentId: 10,
          title: 'Row',
          blockSchema: { typeName: 'Row', label: 'Row', icon: '', type: 'Row', title: 'Row', summary: '' },
          obsoleteClassName: null,
          version: 1,
          canDelete: true,
          canPublish: true,
          canUnpublish: true,
          canCreate: true,
          editLink: null,
          statusFlags: {},
          containerType: 'row' as const,
          allowedTypes: null,
          children: null,
        },
      ],
    });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(section), { wrapper });

    expect(result.current.dialog?.message).toBe(
      'Archive "Tiny Section" and all 1 child element?',
    );
  });

  it('should open dialog when onAction is called', () => {
    const node = createSimpleElement({ canDelete: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(node), { wrapper });

    expect(result.current.dialog?.isOpen).toBe(false);

    act(() => {
      result.current.action?.onAction();
    });

    expect(result.current.dialog?.isOpen).toBe(true);
  });

  it('should call archive mutation on confirm', async () => {
    mockFetchSuccess({});

    const node = createSimpleElement({ id: 42, canDelete: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useArchiveAction(node), { wrapper });

    act(() => {
      result.current.action?.onAction();
    });

    act(() => {
      result.current.dialog?.onConfirm();
    });

    await waitFor(() => {
      expect(vi.mocked(globalThis.fetch)).toHaveBeenCalled();
    });

    const [url] = vi.mocked(globalThis.fetch).mock.calls[0];
    expect(String(url)).toContain('delete');
  });
});
