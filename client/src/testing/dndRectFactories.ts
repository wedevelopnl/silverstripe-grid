import type { ClientRect, DroppableContainer } from '@dnd-kit/core'

/**
 * DOMRect-compatible factory for dnd-kit tests.
 */
export function makeDomRect(left: number, top: number, width: number, height: number): ClientRect {
  return {
    left,
    top,
    width,
    height,
    right: left + width,
    bottom: top + height,
  } as ClientRect
}

/**
 * Minimal DroppableContainer stub for filter-function tests. Only the `id`
 * field is exercised by the filter logic — no DOM node attached.
 */
export function createDroppable(id: string): DroppableContainer {
  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: null },
    rect: { current: null },
  } as unknown as DroppableContainer
}

/**
 * DroppableContainer with a mock DOM node whose getBoundingClientRect() returns
 * the given rect. Required for closestCenterLive and overRectRef capture tests.
 */
export function createDroppableWithRect(
  id: string,
  domRect: { left: number; top: number; width: number; height: number },
): DroppableContainer {
  const mockNode = {
    getBoundingClientRect: () => ({
      left: domRect.left,
      top: domRect.top,
      width: domRect.width,
      height: domRect.height,
      right: domRect.left + domRect.width,
      bottom: domRect.top + domRect.height,
      x: domRect.left,
      y: domRect.top,
      toJSON: () => ({}),
    }),
  } as unknown as HTMLElement

  return {
    id,
    key: id,
    data: { current: undefined },
    disabled: false,
    node: { current: mockNode },
    rect: { current: null },
  } as unknown as DroppableContainer
}
