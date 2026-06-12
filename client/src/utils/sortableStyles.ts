import type { SortingStrategy } from '@dnd-kit/sortable'
import type { Transform } from '@dnd-kit/utilities'
import { CSS } from '@dnd-kit/utilities'

const DRAGGING_OPACITY = 0.3

/**
 * A `SortableContext` strategy that applies no transforms. Used while a
 * cross-container move is previewed via the pending tree: the moved item is
 * already re-rendered into its target slot, so dnd-kit's reorder-preview
 * shuffle would double-count it — and at the design's block sizes the
 * resulting ≈one-block-height shifts move siblings far enough to break the
 * collision/direction math on the next cycle. Items simply stay where the
 * (pending) DOM puts them.
 */
export const noopSortingStrategy: SortingStrategy = () => null

export function buildSortableStyle(
  transform: Transform | null,
  transition: string | undefined,
  isDragging: boolean,
): React.CSSProperties {
  return {
    transform: CSS.Transform.toString(transform),
    transition: transition ?? undefined,
    opacity: isDragging ? DRAGGING_OPACITY : undefined,
  }
}
