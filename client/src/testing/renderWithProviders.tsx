import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, type RenderResult } from '@testing-library/react';
import type { ReactNode } from 'react';
import { GridEditorProvider } from '@/hooks/GridEditorContext';
import { ViewportProvider } from '@/hooks/ViewportContext';

export interface RenderOptions {
  pageId?: number;
  zone?: string;
  viewport?: string;
  queryClient?: QueryClient;
}

export interface RenderWithProvidersResult extends RenderResult {
  queryClient: QueryClient;
}

function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  });
}

export function renderWithProviders(
  ui: ReactNode,
  options: RenderOptions = {},
): RenderWithProvidersResult {
  const {
    pageId = 1,
    zone = 'main',
    viewport = 'md',
    queryClient = createTestQueryClient(),
  } = options;

  const result = render(
    <QueryClientProvider client={queryClient}>
      <GridEditorProvider value={{ pageId, zone }}>
        <ViewportProvider initialViewport={viewport}>
          {ui}
        </ViewportProvider>
      </GridEditorProvider>
    </QueryClientProvider>,
  );

  return { ...result, queryClient };
}

/**
 * Wrapper component for renderHook tests that need providers.
 */
export function createProviderWrapper(options: RenderOptions = {}) {
  const {
    pageId = 1,
    zone = 'main',
    viewport = 'md',
    queryClient = createTestQueryClient(),
  } = options;

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <GridEditorProvider value={{ pageId, zone }}>
          <ViewportProvider initialViewport={viewport}>
            {children}
          </ViewportProvider>
        </GridEditorProvider>
      </QueryClientProvider>
    );
  }

  return { wrapper: Wrapper, queryClient };
}
