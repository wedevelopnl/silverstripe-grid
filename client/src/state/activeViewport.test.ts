import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  getActiveViewport,
  MOBILE_FIRST_PREVIEW_WIDTH,
  resetActiveViewportStore,
  setActiveViewport,
  subscribeActiveViewport,
} from './activeViewport'

vi.mock('@/utils/gridAdapter', () => ({
  getDefaultViewport: () => 'md',
  getViewports: () => [
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
  ],
}))

describe('activeViewport store', () => {
  afterEach(() => {
    resetActiveViewportStore()
  })

  it('exports the mobile-first preview width constant', () => {
    expect(MOBILE_FIRST_PREVIEW_WIDTH).toBe(375)
  })

  it('lazily initialises to the adapter default', () => {
    expect(getActiveViewport()).toBe('md')
  })

  it('updates and notifies subscribers on setActiveViewport', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeActiveViewport(listener)

    setActiveViewport('sm')

    expect(getActiveViewport()).toBe('sm')
    expect(listener).toHaveBeenCalledTimes(1)

    unsubscribe()
  })

  it('does not notify subscribers when value is unchanged', () => {
    setActiveViewport('md')
    const listener = vi.fn()
    const unsubscribe = subscribeActiveViewport(listener)

    setActiveViewport('md')

    expect(listener).not.toHaveBeenCalled()
    unsubscribe()
  })

  it('stops notifying after unsubscribe', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeActiveViewport(listener)
    unsubscribe()

    setActiveViewport('sm')

    expect(listener).not.toHaveBeenCalled()
  })

  it('refuses writes for keys not in the active adapter viewport set', () => {
    const listener = vi.fn()
    const unsubscribe = subscribeActiveViewport(listener)

    setActiveViewport('nonsense')

    // The write is rejected — store keeps the initial default and
    // no subscribers are notified.
    expect(getActiveViewport()).toBe('md')
    expect(listener).not.toHaveBeenCalled()
    unsubscribe()
  })
})

describe('activeViewport store — adapter unavailable', () => {
  afterEach(() => {
    vi.doUnmock('@/utils/gridAdapter')
    vi.resetModules()
  })

  it('refuses setActiveViewport writes when getViewports throws', async () => {
    // Re-mock the adapter BEFORE importing the store so the fresh
    // module picks up the throwing stubs instead of the test-file
    // level mock at the top.
    vi.resetModules()
    vi.doMock('@/utils/gridAdapter', () => ({
      getDefaultViewport: () => {
        throw new Error('adapter unavailable')
      },
      getViewports: () => {
        throw new Error('adapter unavailable')
      },
    }))

    const freshStore = await import('./activeViewport')
    const listener = vi.fn()
    const unsubscribe = freshStore.subscribeActiveViewport(listener)

    // Initial value is '' because ensureInitialised caught the error.
    expect(freshStore.getActiveViewport()).toBe('')

    // Write attempt with any key — must be refused, not silently accepted.
    freshStore.setActiveViewport('anything-goes')

    expect(freshStore.getActiveViewport()).toBe('')
    expect(listener).not.toHaveBeenCalled()
    unsubscribe()
    freshStore.resetActiveViewportStore()
  })
})
