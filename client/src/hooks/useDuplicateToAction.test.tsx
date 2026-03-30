import { describe, it, expect } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createSimpleElement, createSectionNode, resetIdCounter } from '@/testing/factories';
import { mockFetchSuccess, mockFetchError, getFetchCalls } from '@/testing/mockFetch';
import type { ElementNode } from '@/types/elements';

function renderDuplicateToAction(node: ElementNode) {
  const { wrapper } = createProviderWrapper();
  return renderHook(() => useDuplicateToAction(node), { wrapper });
}

describe('useDuplicateToAction', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  it('should return null action and dialog when canCreate is false', () => {
    const node = createSimpleElement({ canCreate: false });
    const { result } = renderDuplicateToAction(node);

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('should return action and dialog when canCreate is true', () => {
    const node = createSimpleElement({ canCreate: true });
    const { result } = renderDuplicateToAction(node);

    expect(result.current.action?.key).toBe('duplicate-to');
    expect(result.current.dialog).not.toBeNull();
  });

  it('should set elementType to container type for container nodes', () => {
    const node = createSectionNode();
    const { result } = renderDuplicateToAction(node);

    expect(result.current.dialog?.elementType).toBe('section');
  });

  it('should set elementType to "element" for simple nodes', () => {
    const node = createSimpleElement();
    const { result } = renderDuplicateToAction(node);

    expect(result.current.dialog?.elementType).toBe('element');
  });

  describe('dialog open/cancel cycle', () => {
    it('should open dialog on action and close on cancel', () => {
      const node = createSimpleElement();
      const { result } = renderDuplicateToAction(node);

      expect(result.current.dialog?.isOpen).toBe(false);

      act(() => {
        result.current.action?.onAction();
      });
      expect(result.current.dialog?.isOpen).toBe(true);

      act(() => {
        result.current.dialog?.onCancel();
      });
      expect(result.current.dialog?.isOpen).toBe(false);
    });

    it('should clear error on cancel', async () => {
      // First trigger an error via failed confirm
      mockFetchError(422, { message: 'Validation error' });
      const node = createSimpleElement({ id: 10 });
      const { result } = renderDuplicateToAction(node);

      act(() => {
        result.current.action?.onAction();
      });

      act(() => {
        result.current.dialog?.onConfirm(2, 'main', 50);
      });

      await waitFor(() => {
        expect(result.current.dialog?.error).toBe('API error 422: Validation error');
      });

      act(() => {
        result.current.dialog?.onCancel();
      });

      expect(result.current.dialog?.error).toBeNull();
    });
  });

  describe('handleConfirm', () => {
    it('should trigger duplicateTo mutation with correct URL and params', async () => {
      mockFetchSuccess({});
      const node = createSimpleElement({ id: 42 });
      const { result } = renderDuplicateToAction(node);

      act(() => {
        result.current.action?.onAction();
      });

      act(() => {
        result.current.dialog?.onConfirm(5, 'sidebar', 99);
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url, init] = getFetchCalls()[0];
      expect(url).toContain('/api/duplicateTo');
      expect(init?.method).toBe('POST');

      const body = JSON.parse(init?.body as string);
      expect(body).toEqual(
        expect.objectContaining({
          id: 42,
          targetPageId: 5,
          targetZone: 'sidebar',
          targetParentId: 99,
        }),
      );
    });

    it('should close dialog and clear error on success', async () => {
      mockFetchSuccess({});
      const node = createSimpleElement({ id: 42 });
      const { result } = renderDuplicateToAction(node);

      act(() => {
        result.current.action?.onAction();
      });
      expect(result.current.dialog?.isOpen).toBe(true);

      act(() => {
        result.current.dialog?.onConfirm(5, 'main', 99);
      });

      await waitFor(() => {
        expect(result.current.dialog?.isOpen).toBe(false);
      });

      expect(result.current.dialog?.error).toBeNull();
    });

    it('should set error state on failure', async () => {
      mockFetchError(500, { message: 'Server error' });
      const node = createSimpleElement({ id: 42 });
      const { result } = renderDuplicateToAction(node);

      act(() => {
        result.current.action?.onAction();
      });

      act(() => {
        result.current.dialog?.onConfirm(5, 'main', 99);
      });

      await waitFor(() => {
        expect(result.current.dialog?.error).toBe('API error 500: Server error');
      });
    });
  });
});
