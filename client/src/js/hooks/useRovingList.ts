import { type RefObject, useCallback, useEffect, useId, useRef, useState } from 'react'

interface UseRovingListOptions {
  /** Number of items in the list; used to clamp and bound navigation. */
  readonly itemCount: number
}

export interface UseRovingListReturn {
  readonly activeIndex: number
  readonly setActiveIndex: (index: number) => void
  /** Receives focus so arrow keys target the list container, not its items. */
  readonly listRef: RefObject<HTMLDivElement>
  /** DOM id for item $index — the target of aria-activedescendant. */
  readonly getItemId: (index: number) => string
  /**
   * Handle ArrowDown/ArrowUp/Home/End. Returns true when the event was
   * consumed, so the caller can `return` early and keep its own Enter/Space
   * handling (which differs per list).
   */
  readonly handleNavigationKeyDown: (e: React.KeyboardEvent<HTMLDivElement>) => boolean
}

/**
 * Roving `aria-activedescendant` navigation for a single list of items, per
 * the W3C APG pattern where keyboard handling lives on the list container and
 * items are not focusable.
 *
 * Navigation deliberately passes over disabled items rather than skipping
 * them, so they stay discoverable; callers gate activation instead.
 */
export function useRovingList({ itemCount }: UseRovingListOptions): UseRovingListReturn {
  const [activeIndex, setActiveIndex] = useState(0)
  const listRef = useRef<HTMLDivElement>(null)
  // Stable DOM id prefix so aria-activedescendant references a real element id.
  const itemIdPrefix = useId()

  const getItemId = useCallback((index: number) => `${itemIdPrefix}item-${index}`, [itemIdPrefix])

  // If the item list shrinks, activeIndex can point past the last item —
  // aria-activedescendant would then reference a dead id and Enter/Space would
  // resolve to undefined. Clamp it back into range.
  useEffect(() => {
    setActiveIndex((i) => Math.min(i, Math.max(0, itemCount - 1)))
  }, [itemCount])

  const handleNavigationKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLDivElement>): boolean => {
      const last = itemCount - 1
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault()
          setActiveIndex((i) => (i >= last ? last : i + 1))
          return true
        case 'ArrowUp':
          e.preventDefault()
          setActiveIndex((i) => (i <= 0 ? 0 : i - 1))
          return true
        case 'Home':
          e.preventDefault()
          setActiveIndex(0)
          return true
        case 'End':
          e.preventDefault()
          setActiveIndex(last)
          return true
        default:
          return false
      }
    },
    [itemCount],
  )

  return {
    activeIndex,
    setActiveIndex,
    listRef,
    getItemId,
    handleNavigationKeyDown,
  }
}
