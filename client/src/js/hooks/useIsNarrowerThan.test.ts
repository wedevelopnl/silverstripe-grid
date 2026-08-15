import { act, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mockResizeObserver } from '@/testing/mockResizeObserver'
import { useIsNarrowerThan } from './useIsNarrowerThan'

let restoreObserver: (() => void) | undefined

afterEach(() => {
  restoreObserver?.()
  restoreObserver = undefined
})

function setup(threshold: number) {
  const observer = mockResizeObserver()
  restoreObserver = observer.restore

  const rendered = renderHook(() => useIsNarrowerThan(threshold))
  const element = document.createElement('div')
  act(() => {
    rendered.result.current[0](element)
  })

  return { ...observer, rendered }
}

describe('useIsNarrowerThan()', () => {
  it('reports roomy until a measurement says otherwise', () => {
    const { rendered } = setup(300)
    expect(rendered.result.current[1]).toBe(false)
  })

  it('reports narrow once the element measures below the threshold', () => {
    const { resize, rendered } = setup(300)

    act(() => resize(299))

    expect(rendered.result.current[1]).toBe(true)
  })

  it('treats a width exactly on the threshold as roomy', () => {
    const { resize, rendered } = setup(300)

    act(() => resize(300))

    expect(rendered.result.current[1]).toBe(false)
  })

  it('flips back to roomy when the element grows again', () => {
    const { resize, rendered } = setup(300)

    act(() => resize(200))
    expect(rendered.result.current[1]).toBe(true)

    act(() => resize(400))
    expect(rendered.result.current[1]).toBe(false)
  })

  it('stays roomy when the environment has no ResizeObserver', () => {
    // Never hide actions behind a menu the user has no cue to open.
    vi.stubGlobal('ResizeObserver', undefined)

    const rendered = renderHook(() => useIsNarrowerThan(300))
    act(() => {
      rendered.result.current[0](document.createElement('div'))
    })

    expect(rendered.result.current[1]).toBe(false)
    vi.unstubAllGlobals()
  })

  it('stops observing when the element detaches', () => {
    const { resize, rendered } = setup(300)

    act(() => resize(100))
    expect(rendered.result.current[1]).toBe(true)

    act(() => {
      rendered.result.current[0](null)
    })
    // The observer is disconnected, so a later resize reaches nothing and the
    // last verdict stands rather than throwing.
    act(() => resize(900))
    expect(rendered.result.current[1]).toBe(true)
  })
})
