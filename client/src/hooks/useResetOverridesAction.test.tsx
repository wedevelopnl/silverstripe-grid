import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { QueryClient } from '@tanstack/react-query';
import { useResetOverridesAction } from './useResetOverridesAction';
import { createProviderWrapper } from '@/testing/renderWithProviders';
import { createTreeApiResponse } from '@/testing/factories';
import { queryKeys } from './queryKeys';

function setupWithOverrides(
  overrideCounts: Record<string, number>,
  viewport = 'md',
  pageId = 1,
  zone = 'main',
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });

  queryClient.setQueryData(
    queryKeys.elementTree.byPage(pageId, zone),
    createTreeApiResponse({ overrideCounts }),
  );

  const { wrapper } = createProviderWrapper({
    pageId,
    zone,
    viewport,
    queryClient,
  });

  return { wrapper, queryClient };
}

describe('useResetOverridesAction', () => {
  it('should set showReset to false when affectedCount is 0', () => {
    const { wrapper } = setupWithOverrides({});

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.showReset).toBe(false);
    expect(result.current.affectedCount).toBe(0);
  });

  it('should set showReset to true when override counts exist', () => {
    const { wrapper } = setupWithOverrides({ _total: 3, md: 2 });

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.showReset).toBe(true);
  });

  it('should use label "Reset all" when active viewport is the default', () => {
    // Default viewport is 'md' per vitest.setup.ts
    const { wrapper } = setupWithOverrides({ _total: 5 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.label).toBe('Reset all');
  });

  it('should use label "Reset viewport" for non-default viewports', () => {
    const { wrapper } = setupWithOverrides({ lg: 2 }, 'lg');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.label).toBe('Reset viewport');
  });

  it('should use _total for affected count when on default viewport', () => {
    const { wrapper } = setupWithOverrides({ _total: 7, md: 3 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.affectedCount).toBe(7);
  });

  it('should use viewport key for affected count on non-default viewport', () => {
    const { wrapper } = setupWithOverrides({ _total: 10, lg: 4 }, 'lg');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.affectedCount).toBe(4);
  });

  it('should use singular "column" when affected count is 1', () => {
    const { wrapper } = setupWithOverrides({ _total: 1 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.dialogMessage).toContain('1 column?');
    expect(result.current.dialogMessage).not.toContain('columns');
  });

  it('should use plural "columns" when affected count is greater than 1', () => {
    const { wrapper } = setupWithOverrides({ _total: 5 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.dialogMessage).toContain('5 columns?');
  });

  it('should open dialog on reset click', () => {
    const { wrapper } = setupWithOverrides({ _total: 2 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    expect(result.current.isDialogOpen).toBe(false);

    act(() => {
      result.current.onResetClick();
    });

    expect(result.current.isDialogOpen).toBe(true);
  });

  it('should close dialog on cancel', () => {
    const { wrapper } = setupWithOverrides({ _total: 2 }, 'md');

    const { result } = renderHook(() => useResetOverridesAction(), { wrapper });

    act(() => {
      result.current.onResetClick();
    });

    act(() => {
      result.current.onCancel();
    });

    expect(result.current.isDialogOpen).toBe(false);
  });
});
