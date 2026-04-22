import { getDefaultViewport, getViewports } from '@/utils/gridAdapter';

/**
 * Pixel width used when previewing a mobile-first viewport (Bootstrap's
 * `xs`, Bulma's `mobile`) whose `minWidth` is 0. Chosen to match the
 * common iPhone portrait / Chrome devtools default.
 */
export const MOBILE_FIRST_PREVIEW_WIDTH = 375;

type Listener = () => void;

let current: string | null = null;
const listeners = new Set<Listener>();

function ensureInitialised(): void {
  if (current !== null) {
    return;
  }

  try {
    current = getDefaultViewport();
  } catch {
    // Adapter config is unavailable (e.g. during early boot in tests
    // with no CMS config global). Keep null; readers that actually
    // need a value will re-try on subsequent calls.
    current = null;
  }
}

export function getActiveViewport(): string {
  ensureInitialised();
  return current ?? '';
}

export function setActiveViewport(key: string): void {
  ensureInitialised();

  if (current === key) {
    return;
  }

  // Reject keys that are not part of the active adapter's viewport set.
  // This preserves the invariant: consumers never observe a viewport key
  // that doesn't correspond to a real adapter viewport.
  try {
    const viewports = getViewports();
    if (!viewports.some((vp) => vp.key === key)) {
      return;
    }
  } catch {
    // Adapter unavailable — accept the write; a follow-up read will
    // still return whatever was set, and the render layer will handle
    // unknown keys.
  }

  current = key;
  for (const listener of listeners) {
    listener();
  }
}

export function subscribeActiveViewport(listener: Listener): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

/** Reset store to uninitialised — test-only utility. */
export function resetActiveViewportStore(): void {
  current = null;
  listeners.clear();
}
