import type { ViewportKey } from '@/types/adapter'
import { getDefaultViewport, getViewports } from '@/utils/gridAdapter'

/**
 * Pixel width used when previewing a mobile-first viewport (Bootstrap's
 * `xs`, Bulma's `mobile`) whose `minWidth` is 0. Chosen to match the
 * common iPhone portrait / Chrome devtools default.
 */
export const MOBILE_FIRST_PREVIEW_WIDTH = 375

type Listener = () => void

let current: ViewportKey | null = null
const listeners = new Set<Listener>()

function ensureInitialised(): void {
  if (current !== null) {
    return
  }

  try {
    current = getDefaultViewport()
  } catch {
    // Adapter config is unavailable (e.g. during early boot in tests
    // with no CMS config global). Keep null; readers that actually
    // need a value will re-try on subsequent calls.
    current = null
  }
}

export function getActiveViewport(): ViewportKey | null {
  ensureInitialised()
  return current
}

export function setActiveViewport(key: string): void {
  ensureInitialised()

  if (current === key) {
    return
  }

  // Reject keys that are not part of the active adapter's viewport set.
  // This preserves the invariant: consumers never observe a viewport key
  // that doesn't correspond to a real adapter viewport. If the adapter
  // config is unavailable (early boot, stub environment), also refuse
  // the write — accepting arbitrary keys would let invalid values flow
  // into `.grid-${key}` CSS class names downstream.
  let match: { key: ViewportKey } | undefined
  try {
    match = getViewports().find((vp) => vp.key === key)
  } catch {
    return
  }
  if (match === undefined) {
    return
  }

  // Mint the brand from the matched config key — never cast `key` directly,
  // so a ViewportKey only ever originates from a validated adapter viewport.
  current = match.key
  for (const listener of listeners) {
    listener()
  }
}

export function subscribeActiveViewport(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

/** Reset store to uninitialised — test-only utility. */
export function resetActiveViewportStore(): void {
  current = null
  listeners.clear()
}
