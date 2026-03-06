import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';

export interface GridEditorContextValue {
  readonly pageId: number;
  readonly pageClass: string;
  readonly zone: string;
}

const GridEditorContext = createContext<GridEditorContextValue | null>(null);

interface GridEditorProviderProps {
  readonly value: GridEditorContextValue;
  readonly children: ReactNode;
}

export function GridEditorProvider({ value, children }: GridEditorProviderProps) {
  return (
    <GridEditorContext.Provider value={value}>
      {children}
    </GridEditorContext.Provider>
  );
}

export function useGridEditorContext(): GridEditorContextValue {
  const value = useContext(GridEditorContext);
  if (value === null) {
    throw new Error('useGridEditorContext must be used within a GridEditorProvider');
  }
  return value;
}
