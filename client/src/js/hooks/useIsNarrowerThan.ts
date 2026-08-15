import { useCallback, useEffect, useState } from 'react'

/**
 * Track whether an element's content box is narrower than `threshold` pixels.
 *
 * Returns a ref callback to attach to the element being measured, and the
 * current verdict. The element's width is set by its container, never by the
 * content this verdict hides or shows, so there is no measure/relayout loop.
 *
 * A CSS container query would avoid the observer entirely, but cannot express
 * this: the actions have to *move* into the JS-driven overflow menu, and
 * CSS-hiding menu items would leave them in the roving-tabindex order — present
 * to the keyboard, invisible to the eye.
 *
 * Falls back to `false` (the roomy layout) when `ResizeObserver` is missing, so
 * a browser without it shows every action rather than hiding them all behind a
 * menu that nothing would tell the user to open.
 */
export function useIsNarrowerThan(
  threshold: number,
): [(node: HTMLElement | null) => void, boolean] {
  const [node, setNode] = useState<HTMLElement | null>(null)
  const [isNarrow, setIsNarrow] = useState(false)

  // A ref callback in state (not useRef) so the effect below re-runs when the
  // element actually mounts — a ref object's mutation would not retrigger it.
  const ref = useCallback((next: HTMLElement | null) => setNode(next), [])

  useEffect(() => {
    if (node === null || typeof ResizeObserver === 'undefined') return

    const observer = new ResizeObserver((entries) => {
      const entry = entries[0]
      if (entry === undefined) return
      setIsNarrow(entry.contentRect.width < threshold)
    })
    observer.observe(node)

    return () => observer.disconnect()
  }, [node, threshold])

  return [ref, isNarrow]
}
