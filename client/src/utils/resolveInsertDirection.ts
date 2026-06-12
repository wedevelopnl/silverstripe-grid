import type { DraggableType, ViewportRect } from '@/types/dnd'

interface Point {
  readonly x: number
  readonly y: number
}

/**
 * Determines whether a dragged item should be placed before or after the
 * element it's hovering over, based on pointer position relative to the
 * target's center.
 *
 * Columns use the X-axis (horizontal layout); all other types use Y-axis.
 */
export function resolveInsertDirection(
  pointer: Point,
  overRect: ViewportRect,
  type: DraggableType,
): 'before' | 'after' {
  const isXAxis = type === 'column'
  const pointerPos = isXAxis ? pointer.x : pointer.y
  const overCenter = isXAxis
    ? overRect.left + overRect.width / 2
    : overRect.top + overRect.height / 2

  return pointerPos < overCenter ? 'before' : 'after'
}
