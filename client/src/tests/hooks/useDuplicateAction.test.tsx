import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useDuplicateAction } from '@/hooks/useDuplicateAction';
import { GridEditorProvider } from '@/hooks/GridEditorContext';
import type { SimpleElementNode } from '@/types/elements';

vi.mock('@/api/endpoints', () => ({
  duplicateElement: vi.fn(),
}));

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <GridEditorProvider value={{ pageId: 1, zone: 'main' }}>
          {children}
        </GridEditorProvider>
      </QueryClientProvider>
    );
  };
}

function makeLeaf(overrides: Partial<SimpleElementNode> = {}): SimpleElementNode {
  return {
    id: 1,
    parentId: 100,
    title: 'My Element',
    blockSchema: { typeName: 'Content', label: 'Content', icon: '', type: 'Content', title: 'My Element', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    ...overrides,
  };
}

describe('useDuplicateAction', () => {
  it('returns null action when canCreate is false', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: false })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).toBeNull();
  });

  it('returns action with key "duplicate" and label "Duplicate" when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: true })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate');
    expect(result.current.action!.label).toBe('Duplicate');
  });

  it('action is not destructive', () => {
    const { result } = renderHook(
      () => useDuplicateAction(makeLeaf({ canCreate: true })),
      { wrapper: createWrapper() },
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
      { wrapper: createWrapper() },
    );

    act(() => {
      result.current.action!.onAction();
    });

    await waitFor(() => {
      expect(duplicateFn).toHaveBeenCalledWith(42, expect.anything());
    });
  });
});
