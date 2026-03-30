import { describe, it, expect } from 'vitest';
import { renderHook } from '@testing-library/react';
import { useQueryClient } from '@tanstack/react-query';
import type { ReactNode } from 'react';
import GridQueryProvider from './QueryProvider';

function Wrapper({ children }: { children: ReactNode }) {
  return <GridQueryProvider>{children}</GridQueryProvider>;
}

describe('GridQueryProvider', () => {
  it('should provide a QueryClient to children', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    });

    expect(result.current).toBeDefined();
  });

  it('should configure retry: false', () => {
    const { result } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    });

    const defaults = result.current.getDefaultOptions();
    expect(defaults.queries?.retry).toBe(false);
  });

  it('should create isolated QueryClient per mount', () => {
    const { result: first } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    });
    const { result: second } = renderHook(() => useQueryClient(), {
      wrapper: Wrapper,
    });

    expect(first.current).not.toBe(second.current);
  });
});
