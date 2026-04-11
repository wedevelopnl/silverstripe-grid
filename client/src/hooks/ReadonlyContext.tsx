import { type ReactNode, createContext, useContext } from 'react';

const ReadonlyContext = createContext<boolean>(false);

interface ReadonlyProviderProps {
  readonly value: boolean;
  readonly children: ReactNode;
}

export function ReadonlyProvider({ value, children }: ReadonlyProviderProps): ReactNode {
  return <ReadonlyContext.Provider value={value}>{children}</ReadonlyContext.Provider>;
}

export function useReadonly(): boolean {
  return useContext(ReadonlyContext);
}
