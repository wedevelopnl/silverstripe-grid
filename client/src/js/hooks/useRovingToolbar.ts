import { type RefObject, useCallback, useRef, useState } from 'react'

interface UseRovingToolbarOptions {
  /** Number of tab stops the toolbar renders — enabled controls only. */
  readonly stopCount: number
}

export interface UseRovingToolbarReturn {
  /** Wraps the toolbar; the key handler reads its buttons off this. */
  readonly toolbarRef: RefObject<HTMLDivElement>
  /** The stop holding `tabIndex={0}`; every other control takes -1. */
  readonly activeStop: number
  readonly handleKeyDown: (e: React.KeyboardEvent<HTMLDivElement>) => void
}

/**
 * Roving tabindex over a toolbar (W3C APG): the whole toolbar is one tab stop
 * and Arrow/Home/End move between its controls.
 *
 * Only enabled buttons take part — a disabled <button> cannot hold focus, so it
 * is skipped rather than being made focusable-but-inert.
 */
export function useRovingToolbar({ stopCount }: UseRovingToolbarOptions): UseRovingToolbarReturn {
  const toolbarRef = useRef<HTMLDivElement>(null)
  const [activeIndex, setActiveIndex] = useState(0)

  // Clamped so a shrinking action set cannot strand the tab stop on a control
  // that no longer renders, which would leave the toolbar unreachable by Tab.
  const activeStop = Math.min(activeIndex, Math.max(0, stopCount - 1))

  const handleKeyDown = useCallback((e: React.KeyboardEvent<HTMLDivElement>) => {
    const buttons = Array.from(
      toolbarRef.current?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)') ?? [],
    )
    const focused = document.activeElement
    const current = focused instanceof HTMLButtonElement ? buttons.indexOf(focused) : -1
    // Focus is on an open overflow menu's container, not a toolbar control —
    // these keys are the menu's to handle, so leave it be.
    if (current === -1) return

    const last = buttons.length - 1
    let next: number
    switch (e.key) {
      case 'ArrowRight':
        next = current >= last ? last : current + 1
        break
      case 'ArrowLeft':
        next = current <= 0 ? 0 : current - 1
        break
      case 'Home':
        next = 0
        break
      case 'End':
        next = last
        break
      default:
        return
    }

    e.preventDefault()
    setActiveIndex(next)
    buttons[next]?.focus()
  }, [])

  return { toolbarRef, activeStop, handleKeyDown }
}
