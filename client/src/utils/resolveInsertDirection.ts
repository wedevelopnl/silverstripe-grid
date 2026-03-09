import type { DraggableType } from '@/types/dnd';

interface Point {
  readonly x: number;
  readonly y: number;
}

interface Rect {
  readonly left: number;
  readonly top: number;
  readonly width: number;
  readonly height: number;
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
  overRect: Rect,
  type: DraggableType,
): 'before' | 'after' {
  const useXAxis = type === 'column';
  const pointerPos = useXAxis ? pointer.x : pointer.y;
  const overCenter = useXAxis
    ? overRect.left + overRect.width / 2
    : overRect.top + overRect.height / 2;

  return pointerPos < overCenter ? 'before' : 'after';
}
