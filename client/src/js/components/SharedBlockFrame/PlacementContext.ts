import { createContext, useContext } from 'react'
import type { SharedBlockReferenceNode } from '@/types/elements'

/**
 * The placement whose block subtree is currently being rendered.
 *
 * Null everywhere outside a shared block frame — including the library editor,
 * which roots the tree at the block itself, so its content stays fully
 * editable there. Inside the frame, the editable components use this to render
 * the block's content read-only: edits to a shared block belong in the
 * library, not inline on a page where the reach of a change is invisible.
 */
export const PlacementContext = createContext<SharedBlockReferenceNode | null>(null)

export function usePlacement(): SharedBlockReferenceNode | null {
  return useContext(PlacementContext)
}
