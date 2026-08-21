import type { ReactNode } from 'react'
import type { ChildOf, ElementNode } from '@/types/elements'
import { isSharedBlockReferenceNode } from '@/types/elements'
import SharedBlockFrame from './SharedBlockFrame'

interface SharedChildProps<TNode extends ElementNode> {
  readonly child: ChildOf<TNode>
  readonly siblings: readonly ElementNode[]
  readonly readonly?: boolean
  /** Renders the concrete node — the same component the container always used. */
  readonly render: (node: TNode) => ReactNode
}

/**
 * Render a container's child, wrapping it in the shared-block frame when it is
 * a placement.
 *
 * The placement's single child is a normal node of exactly the type this slot
 * expects — a row-rooted block inside a section holds a RowNode — so the same
 * `render` runs either way and nothing below the frame knows the difference.
 * Every container level needs this identical branch, which is why it lives here
 * once rather than six times.
 */
export default function SharedChild<TNode extends ElementNode>({
  child,
  siblings,
  readonly = false,
  render,
}: SharedChildProps<TNode>) {
  if (!isSharedBlockReferenceNode(child)) {
    return <>{render(child)}</>
  }

  const root = child.children[0]

  return (
    <SharedBlockFrame node={child} siblings={siblings} readonly={readonly}>
      {/* The server's placement rules guarantee the root matches this slot's
          type — the same hierarchy invariant attachDerivedFields relies on. */}
      {root !== undefined ? render(root as TNode) : null}
    </SharedBlockFrame>
  )
}
