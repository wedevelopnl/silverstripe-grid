import { type ComponentType, createElement } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { getInjector, loadComponent } from './Injector'

interface PartialInjector {
  default?: unknown
  loadComponent?: (name: string, context?: Record<string, unknown>) => ComponentType<unknown>
}

function setInjector(value: PartialInjector): void {
  // Injector is typed as a required window property, but tests need to simulate
  // partial/missing shapes. Cast through unknown to sidestep the required fields.
  ;(window as unknown as { Injector: PartialInjector }).Injector = value
}

function clearInjector(): void {
  ;(window as unknown as { Injector?: PartialInjector }).Injector = undefined
}

afterEach(() => {
  clearInjector()
  vi.restoreAllMocks()
})

describe('getInjector', () => {
  it('returns window.Injector.default when the global is present', () => {
    // getInjector only checks `default !== undefined` — a plain sentinel
    // suffices; a richer stub would suggest a contract the unit doesn't have.
    const container = {}
    setInjector({ default: container, loadComponent: vi.fn() })

    expect(getInjector()).toBe(container)
  })

  it('throws TypeError when the Injector global is missing entirely', () => {
    clearInjector()

    expect(() => getInjector()).toThrow(TypeError)
    expect(() => getInjector()).toThrow(/Injector is not available/)
  })

  it('throws TypeError when Injector.default is undefined', () => {
    setInjector({ loadComponent: vi.fn() })

    expect(() => getInjector()).toThrow(TypeError)
  })
})

describe('loadComponent', () => {
  it('delegates to window.Injector.loadComponent and returns its result', () => {
    const Stub: ComponentType<unknown> = () => createElement('div', { 'data-stub': 'true' })
    const loader = vi.fn(() => Stub)
    setInjector({ default: {}, loadComponent: loader })

    const context = { foo: 'bar' }
    const result = loadComponent('GridEditor', context)

    expect(loader).toHaveBeenCalledWith('GridEditor', context)
    expect(result).toBe(Stub)
  })

  it('passes undefined context through to the global loader', () => {
    const Stub: ComponentType<unknown> = () => null
    const loader = vi.fn(() => Stub)
    setInjector({ default: {}, loadComponent: loader })

    loadComponent('Widget')

    expect(loader).toHaveBeenCalledWith('Widget', undefined)
  })

  it('throws TypeError when the Injector global is missing', () => {
    clearInjector()

    expect(() => loadComponent('GridEditor')).toThrow(TypeError)
    expect(() => loadComponent('GridEditor')).toThrow(/Injector is not available/)
  })

  it('throws TypeError when loadComponent is not a function', () => {
    setInjector({ default: {} })

    expect(() => loadComponent('GridEditor')).toThrow(TypeError)
  })
})
