import type { ViewportRect } from '@/types/dnd'
import type { DropAxis } from '@/utils/resolveDropAxis'

interface Point {
  readonly x: number
  readonly y: number
}

/**
 * Determines whether a dragged item should be placed before or after the
 * element it's hovering over, based on pointer position relative to the
 * target's center on the given axis.
 *
 * The axis comes from `resolveDropAxis` (rendered geometry): full-width
 * targets compare on Y (above/below), narrower targets on X (left/right).
 */
export function resolveInsertDirection(
  pointer: Point,
  overRect: ViewportRect,
  axis: DropAxis,
): 'before' | 'after' {
  const pointerPos = axis === 'x' ? pointer.x : pointer.y
  const overCenter =
    axis === 'x' ? overRect.left + overRect.width / 2 : overRect.top + overRect.height / 2

  return pointerPos < overCenter ? 'before' : 'after'
}
