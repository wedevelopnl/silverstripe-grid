import { renderHook } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

import { useDuplicateToAction } from '@/hooks/useDuplicateToAction';
import { GridEditorProvider } from '@/hooks/GridEditorContext';
import type { SimpleElementNode, SectionNode } from '@/types/elements';

vi.mock('@/api/endpoints', () => ({
  duplicateToElement: vi.fn(),
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
  return {
    id: 1,
    parentId: 100,
    title: 'Section A',
    blockSchema: { typeName: 'Section', label: 'Section', icon: '', type: 'Section', title: 'Section A', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'section',
    allowedTypes: null,
    children: null,
    ...overrides,
  };
}

describe('useDuplicateToAction', () => {
  it('returns null action and dialog when canCreate is false', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: false })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).toBeNull();
    expect(result.current.dialog).toBeNull();
  });

  it('returns action with key "duplicate-to" and label "Duplicate to\u2026" when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.key).toBe('duplicate-to');
    expect(result.current.action!.label).toBe('Duplicate to\u2026');
  });

  it('returns dialog state when canCreate is true', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog).not.toBeNull();
    expect(result.current.dialog!.isOpen).toBe(false);
    expect(result.current.dialog!.error).toBeNull();
  });

  it('sets elementType to "element" for simple element nodes', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf()),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog!.elementType).toBe('element');
  });

  it('sets elementType to "section" for section nodes', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeSection()),
      { wrapper: createWrapper() },
    );

    expect(result.current.dialog!.elementType).toBe('section');
  });

  it('action is not destructive', () => {
    const { result } = renderHook(
      () => useDuplicateToAction(makeLeaf({ canCreate: true })),
      { wrapper: createWrapper() },
    );

    expect(result.current.action).not.toBeNull();
    expect(result.current.action!.destructive).toBeUndefined();
  });
});
