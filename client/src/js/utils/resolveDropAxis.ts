import type { DraggableType } from '@/types/dnd'

export type DropAxis = 'x' | 'y'

/**
 * A target whose rect spans at least this fraction of its parent container's
 * content width is treated as full-width (vertical stacking semantics).
 * With a 12-column grid an 11/12 column is ≈ 91.7% of the row width, so 95%
 * flips only genuinely full-width targets; gutters and wrapper padding only
 * push narrower targets further below the threshold.
 */
export const FULL_WIDTH_RATIO = 0.95

/** Matches the children-wrapper each container marks for axis measurement. */
export const DND_CONTAINER_SELECTOR = '[data-dnd-container]'

/**
 * Decide the pointer-comparison axis for before/after drop placement from
 * rendered geometry: an element spanning (nearly) the full width of its
 * parent container stacks vertically → compare on Y; anything narrower flows
 * horizontally → compare on X. One rule for every draggable type — sections,
 * rows, and content elements always render full-width, so they resolve to Y
 * exactly as the old type-based rule did; only columns vary.
 *
 * The denominator is the marked wrapper's content-box width (rect minus
 * horizontal padding) so wrapper padding cannot drag a genuinely full-width
 * target below the threshold. Both rects are read live in the same call and
 * therefore share the viewport coordinate space, CSS transforms included.
 *
 * Known limitation (accepted in the spec): offsets are invisible to this
 * rule — a width-8/offset-4 column touches the row's right edge, yet its
 * rect is 8/12 wide, so it keeps X-axis semantics. The pending-tree preview
 * shows the true outcome before release, so the miss is cheap.
 *
 * Degraded mode: without a node, a marked ancestor, or a positive content
 * width (e.g. mid-collapse), fall back to the legacy type rule.
 */
export function resolveDropAxis(overNode: HTMLElement | null, type: DraggableType): DropAxis {
  if (overNode !== null) {
    const container = overNode.closest(DND_CONTAINER_SELECTOR)
    if (container instanceof HTMLElement) {
      const style = window.getComputedStyle(container)
      const contentWidth =
        container.getBoundingClientRect().width -
        (Number.parseFloat(style.paddingLeft) || 0) -
        (Number.parseFloat(style.paddingRight) || 0)
      if (contentWidth > 0) {
        return overNode.getBoundingClientRect().width / contentWidth >= FULL_WIDTH_RATIO ? 'y' : 'x'
      }
    }
  }

  return type === 'column' ? 'x' : 'y'
}
