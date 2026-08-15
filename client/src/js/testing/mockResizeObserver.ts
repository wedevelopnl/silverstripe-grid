import { vi } from 'vitest'

/**
 * jsdom implements no ResizeObserver, so components that measure themselves
 * would silently keep their initial verdict forever under test. This installs a
 * controllable stub on `globalThis` and hands back a `resize()` that drives
 * every observer synchronously, so a test can assert both sides of a width
 * breakpoint rather than only the default one.
 *
 * Call inside the test (not at module scope) and use the returned `restore` in
 * cleanup, or rely on the suite's `vi.restoreAllMocks()` afterEach.
 */
export function mockResizeObserver() {
  const observers = new Set<{ callback: ResizeObserverCallback; targets: Set<Element> }>()
  const original = globalThis.ResizeObserver

  class MockResizeObserver implements ResizeObserver {
    private readonly entry: { callback: ResizeObserverCallback; targets: Set<Element> }

    constructor(callback: ResizeObserverCallback) {
      this.entry = { callback, targets: new Set() }
      observers.add(this.entry)
    }

    observe(target: Element) {
      this.entry.targets.add(target)
    }

    unobserve(target: Element) {
      this.entry.targets.delete(target)
    }

    disconnect() {
      observers.delete(this.entry)
    }
  }

  vi.stubGlobal('ResizeObserver', MockResizeObserver)

  /** Report `width` for every observed element and flush the callbacks. */
  function resize(width: number) {
    for (const { callback, targets } of observers) {
      const entries = [...targets].map(
        (target) =>
          ({
            target,
            contentRect: { width, height: 0 } as DOMRectReadOnly,
          }) as ResizeObserverEntry,
      )
      if (entries.length > 0) callback(entries, {} as ResizeObserver)
    }
  }

  function restore() {
    observers.clear()
    vi.stubGlobal('ResizeObserver', original)
  }

  return { resize, restore }
}
