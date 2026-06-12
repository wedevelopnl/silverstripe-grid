import '@testing-library/jest-dom/vitest'
import { afterEach, vi } from 'vitest'
import { resetActiveViewportStore } from './client/src/state/activeViewport'
import type { AdapterConfig } from './client/src/types/adapter'
import type { SilverStripeConfig, SilverStripeI18n } from './client/src/types/silverstripe'

const CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController'

const defaultAdapterConfig: AdapterConfig = {
  viewports: [
    { key: 'xs', label: 'Extra small', minWidth: 0 },
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
    { key: 'lg', label: 'Large', minWidth: 992 },
    { key: 'xl', label: 'Extra large', minWidth: 1200 },
    { key: 'xxl', label: 'Extra extra large', minWidth: 1400 },
  ],
  defaultViewport: 'md',
  columnCount: 12,
  rowClasses: 'row',
  offsetStrategy: 'margin',
  baseWidthClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i + 1), `col-${i + 1}`]),
  ),
  baseOffsetClasses: Object.fromEntries(
    Array.from({ length: 12 }, (_, i) => [String(i), `offset-${i}`]),
  ),
}

const defaultConfig: SilverStripeConfig = {
  SecurityID: 'test-security-id',
  sections: [
    {
      name: CONTROLLER_FQCN,
      url: '/admin/grid',
      controllerLink: '/admin/grid/',
      gridAdapter: defaultAdapterConfig,
    },
  ],
}

// Minimal i18n stub that mirrors the real window.ss.i18n API shape:
// `_t` is a pure dictionary lookup (no substitution), and `inject` handles
// {placeholder} replacement separately. This matches vendor semantics so
// wrapper bugs cannot slip past tests.
const defaultI18n: SilverStripeI18n = {
  _t: (_key: string, fallback: string) => fallback,
  inject: (str: string, params: Record<string, string | number>) =>
    Object.entries(params).reduce(
      (s, [key, value]) => s.replaceAll(`{${key}}`, String(value)),
      str,
    ),
  addDictionary: () => {},
  currentLocale: 'en',
}

// Stub CMS globals before each test file — only applies in browser-like environments
beforeEach(() => {
  if (typeof window === 'undefined') return
  window.ss = {
    config: structuredClone(defaultConfig),
    i18n: defaultI18n,
  }
  // Reset module-scoped viewport store after window.ss is set so the store's
  // lazy init can resolve getDefaultViewport() from the fresh CMS config on
  // first read, preventing state leaks across tests.
  resetActiveViewportStore()
})

afterEach(() => {
  vi.restoreAllMocks()

  // Reset fetch if it was mocked
  if (vi.isMockFunction(globalThis.fetch)) {
    vi.mocked(globalThis.fetch).mockRestore()
  }
})
