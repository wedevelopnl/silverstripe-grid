import { renderHook, act } from '@testing-library/react';

import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';
import { makeLeaf, makeSection } from '../helpers/elementFactories';
import { createGridEditorWrapper } from '../helpers/dndTestUtils';

const mockMutate = vi.hoisted(() => vi.fn());

vi.mock('@/api/endpoints', () => ({
  duplicateToElement: vi.fn(),
}));

vi.mock('@/hooks/useElementMutations', () => ({
  useDuplicateToElement: () => ({ mutate: mockMutate }),
}));

describe('useDuplicateToAction', () => {
  beforeEach(() => {
    mockMutate.mockReset();
  });

  it('returns null action and dialog when canCreate is false', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: false })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('returns action with key "duplicate-to" and label "Duplicate to\u2026" when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate-to');
    expect(result.current.action!.label).toBe('Duplicate to\u2026');
  });

  it('returns dialog state when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog).not.toBeNull();
    expect(result.current.dialog!.isOpen).toBe(false);
    expect(result.current.dialog!.error).toBeNull();
  });

  it('sets elementType to "element" for simple element nodes', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.elementType).toBe('element');
  });

  it('sets elementType to "section" for section nodes', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeSection(1)),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.elementType).toBe('section');
  });

  it('action is not destructive', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.destructive).toBeUndefined();
  });

  it('opens the dialog when action.onAction is called', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    expect(result.current.dialog!.isOpen).toBe(false);

    act(() => {
      result.current.action!.onAction();
    });

    expect(result.current.dialog!.isOpen).toBe(true);
  });

  it('closes the dialog and clears error when onCancel is called', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
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
    expect(result.current.dialog!.error).toBeNull();
  });

  it('closes the dialog on successful confirm', () => {
    mockMutate.mockImplementation((_params: unknown, options: { onSuccess: () => void }) => {
      options.onSuccess();
    });

    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    expect(result.current.dialog!.isOpen).toBe(true);

    act(() => {
      result.current.dialog!.onConfirm(2, 'main', 10);
    });

    expect(result.current.dialog!.isOpen).toBe(false);
    expect(result.current.dialog!.error).toBeNull();
    expect(mockMutate).toHaveBeenCalledWith(
      { id: 1, targetPageId: 2, targetZone: 'main', targetParentId: 10 },
      expect.objectContaining({ onSuccess: expect.any(Function), onError: expect.any(Function) }),
    );
  });

  it('sets error on failed confirm', () => {
    mockMutate.mockImplementation((_params: unknown, options: { onError: (err: Error) => void }) => {
      options.onError(new Error('Network error'));
    });

    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
      { wrapper: createGridEditorWrapper().wrapper },
    );

    act(() => {
      result.current.action!.onAction();
    });

    act(() => {
      result.current.dialog!.onConfirm(2, 'main', 10);
    });

    expect(result.current.dialog!.error).toBe('Network error');
  });
});
