import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type RenderResult, render } from '@testing-library/react'
import { type ReactNode, StrictMode } from 'react'
import { vi } from 'vitest'
import { GridEditorProvider } from '@/hooks/GridEditorContext'
import { CollapseContext, type CollapseState } from '@/hooks/useCollapseState'
import { ViewportProvider } from '@/hooks/ViewportContext'
import type { NodeKey } from '@/types/identity'

export interface RenderOptions {
  pageId?: number
  zone?: string
  viewport?: string
  queryClient?: QueryClient
  /**
   * Seed which node keys start out collapsed. Wraps the rendered tree in a
   * {@link CollapseContext.Provider} whose `toggle` is a `vi.fn()` — tests
   * that want to assert on toggling should capture the mock via
   * `createCollapseStateStub` and pass it directly.
   */
  collapsedKeys?: Iterable<NodeKey>
  /**
   * Explicit collapse state to inject. Overrides `collapsedKeys`. Use when a
   * test needs to spy on `toggle` or inspect the stub's mock calls.
   */
  collapseState?: CollapseState
}

/**
 * Build a stub {@link CollapseState} backed by a plain `Set<NodeKey>`.
 * `isCollapsed` reads from the set; `toggle` is a `vi.fn()` that mutates
 * the set — so tests can both assert calls and observe state transitions.
 */
export function createCollapseStateStub(seed: Iterable<NodeKey> = []): CollapseState & {
  toggle: ReturnType<typeof vi.fn>
} {
  const collapsed = new Set<NodeKey>(seed)
  const toggle = vi.fn((key: NodeKey) => {
    if (collapsed.has(key)) {
      collapsed.delete(key)
    } else {
      collapsed.add(key)
    }
  })
  return {
    isCollapsed: (key: NodeKey) => collapsed.has(key),
    toggle,
  }
}

export interface RenderWithProvidersResult extends RenderResult {
  queryClient: QueryClient
}

function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
        gcTime: 0,
      },
    },
  })
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
    collapsedKeys,
    collapseState,
  } = options

  const resolvedCollapse: CollapseState =
    collapseState ?? createCollapseStateStub(collapsedKeys ?? [])

  const result = render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <GridEditorProvider value={{ pageId, zone }}>
          <ViewportProvider initialViewport={viewport}>
            <CollapseContext.Provider value={resolvedCollapse}>{ui}</CollapseContext.Provider>
          </ViewportProvider>
        </GridEditorProvider>
      </QueryClientProvider>
    </StrictMode>,
  )

  return { ...result, queryClient }
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
    collapsedKeys,
    collapseState,
  } = options

  const resolvedCollapse: CollapseState =
    collapseState ?? createCollapseStateStub(collapsedKeys ?? [])

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <StrictMode>
        <QueryClientProvider client={queryClient}>
          <GridEditorProvider value={{ pageId, zone }}>
            <ViewportProvider initialViewport={viewport}>
              <CollapseContext.Provider value={resolvedCollapse}>
                {children}
              </CollapseContext.Provider>
            </ViewportProvider>
          </GridEditorProvider>
        </QueryClientProvider>
      </StrictMode>
    )
  }

  return { wrapper: Wrapper, queryClient }
}
