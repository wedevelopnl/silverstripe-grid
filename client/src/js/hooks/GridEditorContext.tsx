import type { ReactNode } from 'react'
import { createContext, useContext } from 'react'
import type { GridEditorRootType } from './queryKeys'

export interface GridEditorContextValue {
  readonly pageId: number
  readonly zone: string
  /**
   * Which host the editor is running in. `'sharedBlock'` means `pageId` is a
   * BLOCK id and the whole tree is that block's own subtree — so nothing in it
   * may host or become another block, and there is no page zone for the
   * page-scoped chrome to describe.
   */
  readonly rootType: GridEditorRootType
}

const GridEditorContext = createContext<GridEditorContextValue | null>(null)

interface GridEditorProviderProps {
  readonly value: GridEditorContextValue
  readonly children: ReactNode
}

export function GridEditorProvider({ value, children }: GridEditorProviderProps) {
  return <GridEditorContext.Provider value={value}>{children}</GridEditorContext.Provider>
}

export function useGridEditorContext(): GridEditorContextValue {
  const value = useContext(GridEditorContext)
  if (value === null) {
    throw new Error('useGridEditorContext must be used within a GridEditorProvider')
  }
  return value
}
