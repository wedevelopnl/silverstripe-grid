import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type RenderResult, render } from '@testing-library/react'
import { type ReactNode, StrictMode } from 'react'
import { vi } from 'vitest'
import { GridEditorProvider } from '@/hooks/GridEditorContext'
import type { GridEditorRootType } from '@/hooks/queryKeys'
import { CollapseContext, type CollapseState } from '@/hooks/useCollapseState'
import { setActiveViewport } from '@/state/activeViewport'
import type { NodeKey } from '@/types/identity'

export interface RenderOptions {
  pageId?: number
  zone?: string
  /**
   * Which host the editor is running in. `'sharedBlock'` makes `pageId` a block
   * id and is what the library-editor suppressions key off.
   */
  rootType?: GridEditorRootType
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

/**
 * QueryClient with the suite's standard test defaults (no retries, immediate
 * garbage collection). Tests that need to seed or spy on a client before
 * rendering should use this instead of re-typing the config.
 */
export function createTestQueryClient(): QueryClient {
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
  const { wrapper, queryClient } = createProviderWrapper(options)
  // Object.assign (not spread): spreading RTL's mapped-type RenderResult makes
  // tsc drop the bound query methods from the resulting object type.
  return Object.assign(render(ui, { wrapper }), { queryClient })
}

/**
 * Wrapper component for renderHook tests that need providers.
 */
export function createProviderWrapper(options: RenderOptions = {}) {
  const {
    pageId = 1,
    zone = 'main',
    rootType = 'page',
    viewport = 'md',
    queryClient = createTestQueryClient(),
    collapsedKeys,
    collapseState,
  } = options

  const resolvedCollapse: CollapseState =
    collapseState ?? createCollapseStateStub(collapsedKeys ?? [])

  setActiveViewport(viewport)

  function Wrapper({ children }: { children: ReactNode }) {
    return (
      <StrictMode>
        <QueryClientProvider client={queryClient}>
          <GridEditorProvider value={{ pageId, zone, rootType }}>
            <CollapseContext.Provider value={resolvedCollapse}>{children}</CollapseContext.Provider>
          </GridEditorProvider>
        </QueryClientProvider>
      </StrictMode>
    )
  }

  return { wrapper: Wrapper, queryClient }
}
