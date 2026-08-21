import { createElement } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { allowConsole } from '@/testing/consoleGuard'
import { flushObservers } from '@/testing/flush'

// The real button pulls in TanStack Query and the element-type dialog; the
// bridge's job is only to find its mount point, read the types off it and hand
// them over, so the component is stubbed down to what proves that.
vi.mock('@/components/AddSharedBlockButton/AddSharedBlockButton', () => ({
  default: ({ leafTypes }: { leafTypes: Record<string, unknown> }) =>
    createElement('div', {
      'data-add-shared-block-stub': true,
      'data-leaf-types': Object.keys(leafTypes).join(','),
    }),
}))

const LEAF_TYPES = {
  'App\\Blocks\\Tekst': { label: 'Tekst', icon: 'font-icon-block-content', description: 'Wörter' },
}

/** The transport the server uses — see readLeafTypes for why it is not raw JSON. */
function encode(value: unknown): string {
  return btoa(String.fromCharCode(...new TextEncoder().encode(JSON.stringify(value))))
}

function createHost(leafTypes: string = encode(LEAF_TYPES)): HTMLElement {
  const host = document.createElement('div')
  host.setAttribute('data-grid-add-shared-block', '')
  host.setAttribute('data-grid-leaf-types', leafTypes)
  // The server-rendered fallback the React mount is expected to replace.
  host.innerHTML = '<a class="new-link">Add new shared section</a>'

  return host
}

function stubIn(host: HTMLElement): HTMLElement | null {
  return host.querySelector('[data-add-shared-block-stub]')
}

describe('add shared block bridge', () => {
  const RealMutationObserver = globalThis.MutationObserver
  let observers: MutationObserver[] = []

  beforeEach(() => {
    observers = []
    globalThis.MutationObserver = class extends RealMutationObserver {
      constructor(callback: MutationCallback) {
        super(callback)
        observers.push(this)
      }
    }

    document.body.innerHTML = ''
    vi.resetModules()
  })

  afterEach(() => {
    for (const observer of observers) {
      observer.disconnect()
    }
    globalThis.MutationObserver = RealMutationObserver
    document.body.innerHTML = ''
  })

  it('mounts over the server-rendered fallback link already on the page', async () => {
    const host = createHost()
    document.body.appendChild(host)

    const { registerAddSharedBlockBridge } = await import('./addSharedBlockButton')
    registerAddSharedBlockBridge()
    await flushObservers()

    expect(stubIn(host)).not.toBeNull()
    expect(host.querySelector('.new-link')).toBeNull()
  })

  it('mounts a listing that arrives later, as a Pjax navigation delivers it', async () => {
    const { registerAddSharedBlockBridge } = await import('./addSharedBlockButton')
    registerAddSharedBlockBridge()

    const wrapper = document.createElement('div')
    const host = createHost()
    wrapper.appendChild(host)
    document.body.appendChild(wrapper)

    await flushObservers()

    expect(stubIn(host)).not.toBeNull()
  })

  it('hands the server-rendered element types to the button', async () => {
    const host = createHost()
    document.body.appendChild(host)

    const { mountAddSharedBlockButton } = await import('./addSharedBlockButton')
    mountAddSharedBlockButton(host)
    await flushObservers()

    // Backslashed class names and non-ASCII labels both survive the transport.
    expect(stubIn(host)?.getAttribute('data-leaf-types')).toBe('App\\Blocks\\Tekst')
  })

  it('still mounts with no element types when the payload is unreadable', async () => {
    allowConsole('Could not read the shared block element types')

    const host = createHost('{not json')
    document.body.appendChild(host)

    const { mountAddSharedBlockButton } = await import('./addSharedBlockButton')
    mountAddSharedBlockButton(host)
    await flushObservers()

    expect(stubIn(host)?.getAttribute('data-leaf-types')).toBe('')
  })

  it('mounts once, so an element seen by both the sweep and the observer keeps one root', async () => {
    const host = createHost()
    document.body.appendChild(host)

    const { mountAddSharedBlockButton } = await import('./addSharedBlockButton')
    mountAddSharedBlockButton(host)
    await flushObservers()
    mountAddSharedBlockButton(host)
    await flushObservers()

    expect(host.querySelectorAll('[data-add-shared-block-stub]')).toHaveLength(1)
  })

  it('unmounts when the CMS swaps the listing away', async () => {
    const { registerAddSharedBlockBridge } = await import('./addSharedBlockButton')
    registerAddSharedBlockBridge()

    const host = createHost()
    document.body.appendChild(host)
    await flushObservers()
    expect(stubIn(host)).not.toBeNull()

    host.remove()
    await flushObservers()

    expect(stubIn(host)).toBeNull()
  })
})
