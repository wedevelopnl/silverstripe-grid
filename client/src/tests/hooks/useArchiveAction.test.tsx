import { renderHook, act, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useArchiveAction } from '@/hooks/useArchiveAction';
import { GridEditorProvider } from '@/hooks/GridEditorContext';
import type { SimpleElementNode, SectionNode, ColumnNode, RowNode } from '@/types/elements';

vi.mock('@/api/endpoints', () => ({
  archiveElement: vi.fn(),
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

function makeSection(overrides: Partial<SectionNode> = {}): SectionNode {
  const leaf = makeLeaf();
  return {
    ...leaf,
    containerType: 'section' as const,
    allowedTypes: null,
    children: [
      {
        ...leaf,
        id: 2,
        containerType: 'row' as const,
        allowedTypes: null,
        children: [
          {
            ...leaf,
            id: 3,
            containerType: 'column' as const,
            allowedTypes: null,
            children: [{ ...leaf, id: 4 }],
            gridSettings: {},
          } as ColumnNode,
        ],
      } as RowNode,
    ],
    ...overrides,
  };
}

describe('useArchiveAction', () => {
  it('returns null action when canDelete is false', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ canDelete: false })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('returns archive action when canDelete is true', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ canDelete: true })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('archive');
    expect(result.current.action!.label).toBe('Archive');
    expect(result.current.action!.destructive).toBe(true);
  });

  it('returns dialog state with correct message for leaf element', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf({ title: 'Hero Banner' })),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog).not.toBeNull();
    expect(result.current.dialog!.message).toBe('Archive "Hero Banner"?');
  });

  it('returns dialog state with descendant count for container', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeSection({ title: 'Main Section' })),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog).not.toBeNull();
    // section has row + column + leaf = 3 descendants
    expect(result.current.dialog!.message).toBe('Archive "Main Section" and all 3 child elements?');
  });

  it('opens dialog when action.onAction is called', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createWrapper() },
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
      { wrapper: createWrapper() },
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
      { wrapper: createWrapper() },
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
    const leaf = makeLeaf();
    const singleChildSection: SectionNode = {
      ...leaf,
      title: 'Wrapper',
      containerType: 'section' as const,
      allowedTypes: null,
      children: [{ ...leaf, id: 50 } as RowNode],
    };

    const { result } = renderHook(
      () => useArchiveAction(singleChildSection),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog!.message).toBe('Archive "Wrapper" and all 1 child element?');
  });

  it('dialog title is "Confirm archive"', () => {
    const { result } = renderHook(
      () => useArchiveAction(makeLeaf()),
      { wrapper: createWrapper() },
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
      { wrapper: createWrapper() },
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
});
