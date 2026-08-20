import type * as gridAdapter from '@/utils/gridAdapter'
import { viewportKey } from './factories'

/**
 * Deliberately small three-viewport set (the full six-viewport setup config
 * would bloat the bridge tests' expectations) — shared so the bridge test
 * files can't drift apart. Wire it up inside the hoisted `vi.mock` factory:
 *
 *   vi.mock('@/utils/gridAdapter', async () =>
 *     (await import('@/testing/mockGridAdapter')).mockGridAdapterModule(),
 *   )
 */
export const STUB_VIEWPORTS = [
  { key: viewportKey('xs'), label: 'Extra Small', minWidth: 0 },
  { key: viewportKey('sm'), label: 'Small', minWidth: 576 },
  { key: viewportKey('md'), label: 'Medium', minWidth: 768 },
]

export function mockGridAdapterModule(): Pick<
  typeof gridAdapter,
  'getViewports' | 'getDefaultViewport'
> {
  return {
    getViewports: () => STUB_VIEWPORTS,
    getDefaultViewport: () => viewportKey('md'),
  }
}
