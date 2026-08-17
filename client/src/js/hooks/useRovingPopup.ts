import { type RefObject, useCallback, useEffect, useRef, useState } from 'react'
import { useRovingList } from './useRovingList'

interface UseRovingPopupOptions {
  /** Number of items in the popup; used to clamp and bound navigation. */
  readonly itemCount: number
  /** Index to make active each time the popup opens (0 for a plain menu). */
  readonly seedIndex?: number
}

export interface UseRovingPopupReturn {
  readonly isOpen: boolean
  readonly toggle: () => void
  readonly close: () => void
  readonly activeIndex: number
  /** Wraps trigger + popup; an outside mousedown here closes the popup. */
  readonly wrapperRef: RefObject<HTMLDivElement>
  /** Focus returns here on Escape. */
  readonly triggerRef: RefObject<HTMLButtonElement>
  /** Receives focus on open, so arrow keys target the popup. */
  readonly popupRef: RefObject<HTMLDivElement>
  /** DOM id for item $index — the target of aria-activedescendant. */
  readonly getItemId: (index: number) => string
  /**
   * Handle ArrowDown/ArrowUp/Home/End. Returns true when the event was
   * consumed, so the caller can `return` early and keep its own
   * Enter/Space handling (which differs per popup).
   */
  readonly handleNavigationKeyDown: (e: React.KeyboardEvent<HTMLDivElement>) => boolean
}

/**
 * Open/close state and dismissal for a trigger-plus-popup pair, layered over
 * the roving `aria-activedescendant` navigation in {@link useRovingList}.
 *
 * Owns everything the actions menu (role=menu) and the grid-settings picker
 * (role=listbox) share; each keeps its own markup and its own Enter/Space
 * activation.
 */
export function useRovingPopup({
  itemCount,
  seedIndex = 0,
}: UseRovingPopupOptions): UseRovingPopupReturn {
  const [isOpen, setIsOpen] = useState(false)
  const wrapperRef = useRef<HTMLDivElement>(null)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const {
    activeIndex,
    setActiveIndex,
    listRef: popupRef,
    getItemId,
    handleNavigationKeyDown,
  } = useRovingList({ itemCount })

  const close = useCallback(() => setIsOpen(false), [])
  const toggle = useCallback(() => setIsOpen((prev) => !prev), [])

  // Seed the active item and move focus to the popup container each time it
  // opens, so arrow keys drive the roving aria-activedescendant pattern.
  useEffect(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — on close the {isOpen && …} popup unmounts (popupRef null → focus no-op) and the seed index is re-applied on the next open, so a close-time run is unobservable
    if (!isOpen) return
    setActiveIndex(seedIndex)
    popupRef.current?.focus()
  }, [isOpen, seedIndex, setActiveIndex, popupRef])

  useEffect(() => {
    // Stryker disable next-line ConditionalExpression: Equivalent — the outside-mousedown listener's only side effect is the idempotent close(), so registering it while closed is inert
    if (!isOpen) return

    function handleMouseDown(e: MouseEvent) {
      if (wrapperRef.current && !wrapperRef.current.contains(e.target as Node)) {
        close()
      }
    }

    document.addEventListener('mousedown', handleMouseDown)
    return () => document.removeEventListener('mousedown', handleMouseDown)
  }, [isOpen, close])

  // Escape is bound at document level so it works regardless of focus target.
  useEffect(() => {
    if (!isOpen) return

    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') {
        e.stopPropagation()
        close()
        triggerRef.current?.focus()
      }
    }

    document.addEventListener('keydown', handleKeyDown)
    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [isOpen, close])

  return {
    isOpen,
    toggle,
    close,
    activeIndex,
    wrapperRef,
    triggerRef,
    popupRef,
    getItemId,
    handleNavigationKeyDown,
  }
}
