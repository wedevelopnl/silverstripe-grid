import { useSyncExternalStore, useLayoutEffect } from 'react';
import type { ReactNode } from 'react';
import {
  getActiveViewport,
  setActiveViewport as storeSet,
  subscribeActiveViewport,
} from '@/state/activeViewport';

export interface ViewportContextValue {
  readonly activeViewport: string;
  readonly setActiveViewport: (key: string) => void;
}

interface ViewportProviderProps {
  readonly initialViewport?: string;
  readonly children: ReactNode;
}

/**
 * Provider retained for backwards compatibility with the grid editor tree.
 * Internally it no longer owns state — the shared `activeViewport` store is
 * the single source of truth so the in-editor ViewportSwitcher and the
 * CMS preview bar selector observe the same value.
 */
export function ViewportProvider({ initialViewport, children }: ViewportProviderProps) {
  useLayoutEffect(() => {
    if (initialViewport !== undefined) {
      storeSet(initialViewport);
    }
  }, [initialViewport]);

  return <>{children}</>;
}

export function useViewportContext(): ViewportContextValue {
  const activeViewport = useSyncExternalStore(
    subscribeActiveViewport,
    getActiveViewport,
    getActiveViewport,
  );

  return { activeViewport, setActiveViewport: storeSet };
}
