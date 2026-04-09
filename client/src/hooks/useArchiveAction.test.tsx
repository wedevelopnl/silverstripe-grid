import { describe, it, expect, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useArchiveAction } from '@/hooks/useArchiveAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories';
import { mockFetchSuccess, mockFetchError, getFetchCalls } from '@/testing/mockFetch';
import type { ElementNode } from '@/types/elements';

function renderArchiveAction(node: ElementNode) {
  const { wrapper } = createProviderWrapper();
  return renderHook(() => useArchiveAction(node), { wrapper });
}

describe('useArchiveAction', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  describe('buildArchiveMessage via dialog.message', () => {
    it('should show simple message for leaf element with no descendants', () => {
      const node = createSimpleElement({ title: 'My Element' });
      const { result } = renderArchiveAction(node);

      expect(result.current.dialog?.message).toBe('Archive "My Element"?');
    });

    it('should show singular child message for 1 descendant', () => {
      const column = createColumnNode({
        title: 'My Column',
        childCount: 1,
      });
      const { result } = renderArchiveAction(column);

      expect(result.current.dialog?.message).toBe('Archive "My Column" and all 1 child element?');
    });

    it('should show plural children message for multiple descendants', () => {
      const column = createColumnNode({
        title: 'My Column',
        childCount: 3,
      });
      const { result } = renderArchiveAction(column);

      expect(result.current.dialog?.message).toBe('Archive "My Column" and all 3 child elements?');
    });

    it('should count nested descendants recursively', () => {
      const section = createSectionNode({
        title: 'My Section',
        children: [
          createRowNode({
            children: [createColumnNode({ childCount: 2 })],
          }),
        ],
      });
      const { result } = renderArchiveAction(section);

      // section > row(1) > column(1) > 2 elements = 4 descendants
      expect(result.current.dialog?.message).toContain('4 child elements');
    });
  });

  describe('canDelete guard', () => {
    it('should return null action when canDelete is false', () => {
      const node = createSimpleElement({ canDelete: false });
      const { result } = renderArchiveAction(node);

      expect(result.current.action).toBeNull();
      expect(result.current.dialog).toBeNull();
    });

    it('should return action when canDelete is true', () => {
      const node = createSimpleElement({ canDelete: true });
      const { result } = renderArchiveAction(node);

      expect(result.current.action).not.toBeNull();
      expect(result.current.action?.key).toBe('archive');
    });
  });

  describe('dialog open/cancel cycle', () => {
    it('should open dialog on action and close on cancel', () => {
      const node = createSimpleElement();
      const { result } = renderArchiveAction(node);

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
  });

  describe('handleConfirm', () => {
    it('should trigger archive mutation with correct URL', async () => {
      mockFetchSuccess({});
      const node = createSimpleElement({ id: 42 });
      const { result } = renderArchiveAction(node);

      act(() => {
        result.current.action?.onAction();
      });

      act(() => {
        result.current.dialog?.onConfirm();
      });

      await waitFor(() => {
        expect(getFetchCalls().length).toBeGreaterThan(0);
      });

      const [url, init] = getFetchCalls()[0];
      expect(url).toContain('/api/delete');
      expect(init?.method).toBe('DELETE');
    });

    it('should close dialog on confirm', () => {
      mockFetchSuccess({});
      const node = createSimpleElement();
      const { result } = renderArchiveAction(node);

      act(() => {
        result.current.action?.onAction();
      });
      expect(result.current.dialog?.isOpen).toBe(true);

      act(() => {
        result.current.dialog?.onConfirm();
      });
      expect(result.current.dialog?.isOpen).toBe(false);
    });

    it('should show toast on error', async () => {
      mockFetchError(500, { message: 'Server error' });
      const dispatch = vi.fn();
      window.ss.store = { dispatch };

      const node = createSimpleElement({ id: 99 });
      const { result } = renderArchiveAction(node);

      act(() => {
        result.current.action?.onAction();
      });

      act(() => {
        result.current.dialog?.onConfirm();
      });

      await waitFor(() => {
        expect(dispatch).toHaveBeenCalledWith(
          expect.objectContaining({
            type: 'DISPLAY_TOAST',
            payload: expect.objectContaining({ type: 'error' }),
          }),
        );
      });
    });
  });
});
