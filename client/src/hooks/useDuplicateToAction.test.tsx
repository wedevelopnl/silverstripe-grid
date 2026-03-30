import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useDuplicateToAction } from './useDuplicateToAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createSimpleElement, createSectionNode } from '@/testing/factories';

describe('useDuplicateToAction', () => {
  it('should return null action and dialog when canCreate is false', () => {
    const node = createSimpleElement({ canCreate: false });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(node), { wrapper });

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('should return action with key "duplicate-to" when canCreate is true', () => {
    const node = createSimpleElement({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(node), { wrapper });

    expect(result.current.action).not.toBeNull();
    expect(result.current.action?.key).toBe('duplicate-to');
    expect(result.current.action?.label).toContain('Duplicate to');
  });

  it('should open dialog when onAction is called', () => {
    const node = createSimpleElement({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(node), { wrapper });

    expect(result.current.dialog?.isOpen).toBe(false);

    act(() => {
      result.current.action?.onAction();
    });

    expect(result.current.dialog?.isOpen).toBe(true);
  });

  it('should set elementType to "element" for simple element nodes', () => {
    const node = createSimpleElement({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(node), { wrapper });

    expect(result.current.dialog?.elementType).toBe('element');
  });

  it('should set elementType to container type for container nodes', () => {
    const section = createSectionNode({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(section), { wrapper });

    expect(result.current.dialog?.elementType).toBe('section');
  });

  it('should close dialog and clear error on cancel', () => {
    const node = createSimpleElement({ canCreate: true });
    const { wrapper } = createProviderWrapper();

    const { result } = renderHook(() => useDuplicateToAction(node), { wrapper });

    act(() => {
      result.current.action?.onAction();
    });

    expect(result.current.dialog?.isOpen).toBe(true);

    act(() => {
      result.current.dialog?.onCancel();
    });

    expect(result.current.dialog?.isOpen).toBe(false);
    expect(result.current.dialog?.error).toBeNull();
  });
});
