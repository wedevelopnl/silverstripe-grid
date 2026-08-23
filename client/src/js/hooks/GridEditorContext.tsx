import type { ReactNode } from 'react'
import { createContext, useContext } from 'react'
import type { EditorRoot, PageRoot } from '@/types/editorRoot'

const GridEditorContext = createContext<EditorRoot | null>(null)

interface GridEditorProviderProps {
  readonly root: EditorRoot
  readonly children: ReactNode
}

export function GridEditorProvider({ root, children }: GridEditorProviderProps) {
  return <GridEditorContext.Provider value={root}>{children}</GridEditorContext.Provider>
}

/** What this editor is rooted at — a page zone, or a block in the library. */
export function useEditorRoot(): EditorRoot {
  const root = useContext(GridEditorContext)
  if (root === null) {
    throw new Error('useEditorRoot must be used within a GridEditorProvider')
  }
  return root
}

/**
 * The page root, for chrome that exists only in the page editor: the viewport
 * control and its reset scopes, both of which address a page + zone the server
 * has no block equivalent for.
 *
 * Throws rather than degrading to a no-op. The library editor renders none of
 * this — `GridAreaHeader` hides the whole strip there — so arriving here
 * block-rooted is a wiring mistake, and a silent empty state would hide it.
 */
export function usePageRoot(): PageRoot {
  const root = useEditorRoot()
  if (root.kind !== 'page') {
    throw new Error('usePageRoot: this editor is rooted at a shared block, not a page')
  }
  return root
}
