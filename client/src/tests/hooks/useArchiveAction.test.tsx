import { renderHook, act, waitFor } from '@testing-library/react';

import { useArchiveAction } from '@/hooks/useArchiveAction';
import { makeLeaf, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';
import { createGridEditorWrapper } from '../helpers/dndTestUtils';

vi.mock('@/api/endpoints', () => ({
  archiveElement: vi.fn(),
}));

vi.mock('@/utils/toast', () => ({
  showToast: vi.fn(),
}));

describe('useArchiveAction', () => {
  it('returns null action when canDelete is false', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ canDelete: false })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('returns archive action when canDelete is true', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ canDelete: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('archive');
    expect(result.current.action!.label).toBe('Archive');
    expect(result.current.action!.destructive).toBe(true);
  });

  it('returns dialog state with correct message for leaf element', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ title: 'Hero Banner' })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog).not.toBeNull();
    expect(result.current.dialog!.message).toBe('Archive "Hero Banner"?');
  });

  it('returns dialog state with descendant count for container', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeSection(1, [
        makeRow(2, [makeColumn(3, [makeLeaf({ id: 4 })], 2)], 1),
      ], 42, { title: 'Main Section' })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog).not.toBeNull();
    // section has row + column + leaf = 3 descendants
    expect(result.current.dialog!.message).toBe('Archive "Main Section" and all 3 child elements?');
  });

  it('opens dialog when action.onAction is called', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.isOpen).toBe(false);

    act(() => {
      result.current.action!.onAction();
    });

    expect(result.current.dialog!.isOpen).toBe(true);
  });

  it('closes dialog when onCancel is called', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });
    expect(result.current.dialog!.isOpen).toBe(true);

    act(() => {
      result.current.dialog!.onCancel();
    });
    expect(result.current.dialog!.isOpen).toBe(false);
  });

  it('closes dialog when onConfirm is called', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    act(() => {
      result.current.dialog!.onConfirm();
    });

    expect(result.current.dialog!.isOpen).toBe(false);
  });

  it('uses singular "child element" when container has exactly 1 descendant', () => {
    const singleChildSection = makeSection(1, [makeRow(50)], 42, { title: 'Wrapper' });

    const { result } = renderHook(
      () => useArchiveAction(singleChildSection),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.message).toBe('Archive "Wrapper" and all 1 child element?');
  });

  it('dialog title is "Confirm archive"', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.title).toBe('Confirm archive');
  });

  it('calls archiveElement endpoint with the node id on confirm', async () => {
    const { archiveElement: mockArchive } = await import('@/api/endpoints');
    const archiveFn = mockArchive as ReturnType<typeof vi.fn>;
    archiveFn.mockClear();
    archiveFn.mockResolvedValue(undefined);

    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ id: 77 })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    act(() => {
      result.current.dialog!.onConfirm();
    });

    // The mutation is async — wait for TanStack Query to invoke mutationFn
    await waitFor(() => {
      expect(archiveFn).toHaveBeenCalledWith(77, expect.anything());
    });
  });

  it('calls showToast with error message when archive mutation fails', async () => {
    const { archiveElement: mockArchive } = await import('@/api/endpoints');
    const { showToast: mockShowToast } = await import('@/utils/toast');
    const archiveFn = mockArchive as ReturnType<typeof vi.fn>;
    const showToastFn = mockShowToast as ReturnType<typeof vi.fn>;
    archiveFn.mockClear();
    showToastFn.mockClear();
    archiveFn.mockRejectedValue(new Error('Archive failed'));

    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ id: 88 })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    act(() => {
      result.current.dialog!.onConfirm();
    });

    await waitFor(() => {
      expect(showToastFn).toHaveBeenCalledWith('Archive failed');
    });
  });
});
