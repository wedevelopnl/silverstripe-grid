import { renderHook, act, waitFor } from '@testing-library/react';

import { useDuplicateAction } from '@/hooks/useDuplicateAction';
import { makeLeaf } from '../helpers/elementFactories';
import { createGridEditorWrapper } from '../helpers/dndTestUtils';

vi.mock('@/api/endpoints', () => ({
  duplicateElement: vi.fn(),
  duplicateToElement: vi.fn(),
}));

vi.mock('@/utils/toast', () => ({
  showToast: vi.fn(),
}));

describe('useDuplicateAction', () => {
  it('returns null action when canCreate is false', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: false })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).toBeNull();
  });

  it('returns action with key "duplicate" and label "Duplicate" when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate');
    expect(result.current.action!.label).toBe('Duplicate');
  });

  it('action is not destructive', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.destructive).toBeUndefined();
  });

  it('calls duplicateElement endpoint with the node id on action', async () => {
    const { duplicateElement: mockDuplicate } = await import('@/api/endpoints');
    const duplicateFn = mockDuplicate as ReturnType<typeof vi.fn>;
    duplicateFn.mockClear();
    duplicateFn.mockResolvedValue(undefined);

    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ id: 42 })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    await waitFor(() => {
      expect(duplicateFn).toHaveBeenCalledWith(42, expect.anything());
    });
  });

  it('calls showToast with error message when duplicate mutation fails', async () => {
    const { duplicateElement: mockDuplicate } = await import('@/api/endpoints');
    const { showToast: mockShowToast } = await import('@/utils/toast');
    const duplicateFn = mockDuplicate as ReturnType<typeof vi.fn>;
    const showToastFn = mockShowToast as ReturnType<typeof vi.fn>;
    duplicateFn.mockClear();
    showToastFn.mockClear();
    duplicateFn.mockRejectedValue(new Error('Duplicate failed'));

    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ id: 55 })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    await waitFor(() => {
      expect(showToastFn).toHaveBeenCalledWith('Duplicate failed');
    });
  });
});
