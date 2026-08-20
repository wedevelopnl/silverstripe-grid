import type { ReactNode } from 'react'
import { vi } from 'vitest'

/**
 * Shared dnd-kit test doubles for block/card component tests. Wire them up
 * inside the (hoisted) `vi.mock` factories via a dynamic import:
 *
 *   vi.mock('@dnd-kit/sortable', async () =>
 *     (await import('@/testing/mockDndKit')).mockSortableModule(),
 *   )
 *   vi.mock('@dnd-kit/core', async (importOriginal) =>
 *     (await import('@/testing/mockDndKit')).mockDndCoreModule(await importOriginal()),
 *   )
 *   vi.mock('@/hooks/useDragAndDrop', async () =>
 *     (await import('@/testing/mockDndKit')).mockUseDragContextModule(),
 *   )
 *
 * and reset per test with `resetDndMocks({ useSortable, useDragContext })`.
 */

/** The stable useSortable return block/card tests render with. */
export const defaultSortable = {
  attributes: {},
  listeners: {},
  setNodeRef: vi.fn(),
  transform: null,
  transition: undefined,
  isDragging: false,
  isOver: false,
}

export const defaultDragContext = { activeType: null, pendingActive: false }

export function Passthrough({ children }: { children?: ReactNode }) {
  return <>{children}</>
}

export function mockSortableModule() {
  return {
    useSortable: vi.fn(() => ({ ...defaultSortable })),
    SortableContext: Passthrough,
    verticalListSortingStrategy: {},
    horizontalListSortingStrategy: {},
  }
}

/**
 * Real @dnd-kit/core with DndContext (plus any `extras`, e.g. DragOverlay)
 * replaced by children pass-throughs.
 */
export function mockDndCoreModule(
  actual: Record<string, unknown>,
  extras: Record<string, unknown> = {},
) {
  return {
    ...actual,
    DndContext: Passthrough,
    ...extras,
  }
}

export function mockUseDragContextModule() {
  return {
    useDragContext: vi.fn(() => ({ ...defaultDragContext })),
  }
}

type AnyHook = (...args: never[]) => unknown

/**
 * Restore the mocked hooks' default returns after a test overrode them.
 * Pass the (mocked) hooks imported by the test file.
 */
export function resetDndMocks(hooks: { useSortable?: AnyHook; useDragContext?: AnyHook }): void {
  if (hooks.useSortable) {
    vi.mocked(hooks.useSortable).mockReturnValue({ ...defaultSortable } as never)
  }
  if (hooks.useDragContext) {
    vi.mocked(hooks.useDragContext).mockReturnValue({ ...defaultDragContext } as never)
  }
}
