import type { ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DndContext } from '@dnd-kit/core';
import { SortableContext } from '@dnd-kit/sortable';
import { DragContext } from '@/hooks/useDragAndDrop';
import type { DraggableType } from '@/types/dnd';
import { ViewportProvider } from '@/hooks/ViewportContext';
import { GridEditorProvider } from '@/hooks/GridEditorContext';

/**
 * Creates a test wrapper that provides all context ancestors needed by
 * sortable grid components: QueryClient, GridEditorContext, DndContext,
 * DragContext, SortableContext, and ViewportProvider.
 */
export function createDndWrapper(
  activeViewport = 'md',
  items: string[] = [],
  activeType: DraggableType | null = null,
): ({ children }: { children: ReactNode }) => ReactNode {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return function DndWrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <GridEditorProvider value={{ pageId: 1, zone: 'main' }}>
          <DndContext>
            <DragContext.Provider value={{ activeType }}>
              <SortableContext items={items}>
                <ViewportProvider initialViewport={activeViewport}>
                  {children}
                </ViewportProvider>
              </SortableContext>
            </DragContext.Provider>
          </DndContext>
        </GridEditorProvider>
      </QueryClientProvider>
    );
  };
}
