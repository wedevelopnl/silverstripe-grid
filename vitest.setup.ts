import '@testing-library/jest-dom/vitest'
import { afterEach, beforeEach, vi } from 'vitest'
import failOnConsole from 'vitest-fail-on-console'
import { resetActiveViewportStore } from './client/src/js/state/activeViewport'
import {
  isConsoleMessageAllowed,
  resetConsoleAllowlist,
} from './client/src/js/testing/consoleGuard'
import { viewportKey } from './client/src/js/testing/factories'
import { createMockLocalStorage } from './client/src/js/testing/mockLocalStorage'
import type { AdapterConfig } from './client/src/js/types/adapter'
import type { SilverStripeConfig, SilverStripeI18n } from './client/src/js/types/silverstripe'

// Fail any test that logs an unexpected console.error/warn. Tests opt intentional
// output in via allowConsole() (see consoleGuard.ts); render-throw tests use
// renderExpectingError, which swallows React's diagnostics locally.
failOnConsole({
  shouldFailOnError: true,
  shouldFailOnWarn: true,
  silenceMessage: (message) => isConsoleMessageAllowed(message),
})

// jsdom does no layout, so it ships no Element.scrollIntoView at all. The
// roving-list hook calls it to keep the active option visible; without a stub
// every arrow keypress in a test would throw.
if (typeof Element !== 'undefined' && Element.prototype.scrollIntoView === undefined) {
  Element.prototype.scrollIntoView = function scrollIntoView() {}
}

const CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\GridController'
const SHARED_BLOCK_CONTROLLER_FQCN = 'WeDevelop\\Grid\\Controllers\\SharedBlockController'

const defaultAdapterConfig: AdapterConfig = {
  viewports: [
    { key: viewportKey('xs'), label: 'Extra small', minWidth: 0 },
    { key: viewportKey('sm'), label: 'Small', minWidth: 576 },
    { key: viewportKey('md'), label: 'Medium', minWidth: 768 },
    { key: viewportKey('lg'), label: 'Large', minWidth: 992 },
    { key: viewportKey('xl'), label: 'Extra large', minWidth: 1200 },
    { key: viewportKey('xxl'), label: 'Extra extra large', minWidth: 1400 },
  ],
  defaultViewport: viewportKey('md'),
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
      controllerLink: '/admin/grid',
      gridAdapter: defaultAdapterConfig,
    },
    // The block library is a second AdminController, so the CMS publishes it as
    // its own section with its own base URL. No gridAdapter: only the grid
    // section carries one, and getAdapterConfig() reads it from there.
    {
      name: SHARED_BLOCK_CONTROLLER_FQCN,
      url: '/admin/grid-shared-blocks',
      controllerLink: '/admin/grid-shared-blocks',
    },
  ],
}

// Minimal i18n stub that mirrors the real window.ss.i18n API shape:
// `_t` is a pure dictionary lookup (no substitution), and `inject` handles
// {placeholder} replacement separately. This matches vendor semantics so
// wrapper bugs cannot slip past tests.
const defaultI18n: SilverStripeI18n = {
  _t: (_key: string, fallback: string) => fallback,
  // Deliberately reproduces the vendor replacer, falsy-value bug and all
  // (`map[key] ? map[key] : match`), so a wrapper that delegates substitution
  // to it fails here the same way it fails in the CMS. Our `t()` does not
  // route through this — see the note in client/src/js/i18n/index.ts.
  inject: (str: string, params: Record<string, string | number>) =>
    str.replace(/\{([A-Za-z0-9_]*)\}/g, (match, key: string) =>
      params[key] ? String(params[key]) : match,
    ),
  addDictionary: () => {},
  currentLocale: 'en',
}

// Stub CMS globals before each test file — only applies in browser-like environments
beforeEach(() => {
  resetConsoleAllowlist()

  if (typeof window === 'undefined') return

  // jsdom implements the <dialog> `open` reflection but not showModal/close.
  // Stub them (tracking `open`) with fresh mocks per test so call assertions
  // don't leak across tests.
  HTMLDialogElement.prototype.showModal = vi.fn(function showModal(this: HTMLDialogElement) {
    this.setAttribute('open', '')
  })
  HTMLDialogElement.prototype.close = vi.fn(function close(this: HTMLDialogElement) {
    this.removeAttribute('open')
  })

  // jsdom's localStorage is shadowed by Node's (unavailable) native one, so
  // provide a fresh, isolated Map-backed store for every test.
  Object.defineProperty(globalThis, 'localStorage', {
    value: createMockLocalStorage(),
    writable: true,
    configurable: true,
  })

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
})
