import { useMemo, useSyncExternalStore } from 'react'
import {
  getActiveViewport,
  setActiveViewport as storeSet,
  subscribeActiveViewport,
} from '@/state/activeViewport'

export interface ViewportContextValue {
  readonly activeViewport: string
  readonly setActiveViewport: (key: string) => void
}

/**
 * Reads the shared `activeViewport` store. The in-editor ViewportSwitcher and
 * the CMS preview bar selector observe the same value through this hook — there
 * is no provider; the store is the single source of truth.
 */
export function useViewportContext(): ViewportContextValue {
  const activeViewport = useSyncExternalStore(
    subscribeActiveViewport,
    getActiveViewport,
    getActiveViewport,
  )

  // Stable object reference per `activeViewport` change so consumers using the
  // return value as a prop, dependency, or memo input don't see a new identity
  // on every render of an unrelated parent.
  return useMemo(() => ({ activeViewport, setActiveViewport: storeSet }), [activeViewport])
}
