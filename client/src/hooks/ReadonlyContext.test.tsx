import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ReadonlyProvider, useReadonly } from './ReadonlyContext';

describe('ReadonlyContext', () => {
  it('defaults to false when no provider', () => {
    const { result } = renderHook(() => useReadonly());
    expect(result.current).toBe(false);
  });

  it('returns true when provider value is true', () => {
    const { result } = renderHook(() => useReadonly(), {
      wrapper: ({ children }) => <ReadonlyProvider value={true}>{children}</ReadonlyProvider>,
    });
    expect(result.current).toBe(true);
  });

  it('returns false when provider value is false', () => {
    const { result } = renderHook(() => useReadonly(), {
      wrapper: ({ children }) => <ReadonlyProvider value={false}>{children}</ReadonlyProvider>,
    });
    expect(result.current).toBe(false);
  });
});
