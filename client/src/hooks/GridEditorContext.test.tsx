import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { GridEditorProvider, useGridEditorContext } from './GridEditorContext';

describe('useGridEditorContext', () => {
  it('returns pageId and zone from provider', () => {
    function Wrapper({ children }: { children: ReactNode }) {
      return (
        <GridEditorProvider value={{ pageId: 42, zone: 'sidebar' }}>{children}</GridEditorProvider>
      );
    }

    const { result } = renderHook(() => useGridEditorContext(), {
      wrapper: Wrapper,
    });

    expect(result.current.pageId).toBe(42);
    expect(result.current.zone).toBe('sidebar');
  });
});
