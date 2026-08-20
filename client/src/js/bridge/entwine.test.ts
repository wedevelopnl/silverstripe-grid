import { createElement } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushObservers } from '@/testing/flush'

// Return a trivial component from the Injector so the real GridEditor (which
// pulls in TanStack Query, DnD, etc.) never loads during the test. We only
// care that the bridge reaches the mount path.
vi.mock('./Injector', () => ({
  loadComponent: vi.fn(() => () => createElement('div', { 'data-grid-editor-stub': 'true' })),
}))

const HOST_SELECTOR = '[data-react-mount="grid-editor"]'

function createHost(): HTMLElement {
  const host = document.createElement('div')
  host.setAttribute('data-react-mount', 'grid-editor')
  host.setAttribute('data-schema', JSON.stringify({ 'grid-page-id': 1, 'grid-zone': 'main' }))
  return host
}

describe('entwine bridge MutationObserver fallback', () => {
  const RealMutationObserver = globalThis.MutationObserver
  let observers: MutationObserver[] = []

  beforeEach(() => {
    // Importing the bridge attaches a MutationObserver to document.body as a
    // top-level side effect. Since each test re-imports the module (below),
    // every import spawns a fresh observer — track them so afterEach can
    // disconnect them. Without this, a leaked observer from a previous test
    // keeps observing document.body and double-mounts the next test's host.
    observers = []
    globalThis.MutationObserver = class extends RealMutationObserver {
      constructor(callback: MutationCallback) {
        super(callback)
        observers.push(this)
      }
    }

    document.body.innerHTML = '<div class="js-injector-boot"></div>'
    vi.resetModules()
  })

  afterEach(() => {
    for (const observer of observers) {
      observer.disconnect()
    }
    globalThis.MutationObserver = RealMutationObserver
    document.body.innerHTML = ''
  })

  it('mounts the grid editor when a host element is inserted after initial load (Pjax simulation)', async () => {
    await import('./entwine')

    const bootRoot = document.querySelector('.js-injector-boot')
    expect(bootRoot).not.toBeNull()

    const host = createHost()
    bootRoot?.appendChild(host)

    await flushObservers()

    expect(host.getAttribute('data-grid-editor-mounted')).toBe('true')
    expect(host.querySelector('[data-grid-editor-stub="true"]')).not.toBeNull()
  })

  it('ignores mutations inside an already-mounted editor host', async () => {
    await import('./entwine')

    const bootRoot = document.querySelector('.js-injector-boot')
    const host = createHost()
    bootRoot?.appendChild(host)
    await flushObservers()
    expect(host.getAttribute('data-grid-editor-mounted')).toBe('true')

    // Content churn inside a mounted editor is React-managed and can never
    // contain a real mount host — a marker element appearing there must NOT
    // be picked up by the Pjax observer.
    const nested = createHost()
    host.appendChild(nested)
    await flushObservers()

    expect(nested.getAttribute('data-grid-editor-mounted')).toBeNull()
  })

  it('unmounts when the host element is removed from the DOM', async () => {
    await import('./entwine')

    const bootRoot = document.querySelector('.js-injector-boot')
    const host = createHost()
    bootRoot?.appendChild(host)
    await flushObservers()

    expect(host.getAttribute('data-grid-editor-mounted')).toBe('true')

    host.remove()
    await flushObservers()

    expect(document.querySelector(`${HOST_SELECTOR}[data-grid-editor-mounted="true"]`)).toBeNull()
  })
})
