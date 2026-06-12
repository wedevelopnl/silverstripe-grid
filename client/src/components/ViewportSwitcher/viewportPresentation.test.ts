import { describe, expect, it } from 'vitest'
import type { ViewportConfig } from '@/types/adapter'
import { getViewportIcon, getViewportRangeLabel } from './viewportPresentation'

describe('getViewportIcon', () => {
  it.each([
    { minWidth: 0, expected: 'font-icon-mobile' },
    { minWidth: 575, expected: 'font-icon-mobile' },
    { minWidth: 640, expected: 'font-icon-mobile' },
    { minWidth: 768, expected: 'font-icon-tablet' },
    { minWidth: 992, expected: 'font-icon-tablet' },
    { minWidth: 1024, expected: 'font-icon-monitor' },
    { minWidth: 1400, expected: 'font-icon-monitor' },
  ])('returns $expected for minWidth=$minWidth', ({ minWidth, expected }) => {
    expect(getViewportIcon(minWidth)).toBe(expected)
  })
})

describe('getViewportRangeLabel', () => {
  const viewports: readonly ViewportConfig[] = [
    { key: 'xs', label: 'Extra small', minWidth: 0 },
    { key: 'sm', label: 'Small', minWidth: 576 },
    { key: 'md', label: 'Medium', minWidth: 768 },
    { key: 'xxl', label: 'Extra extra large', minWidth: 1400 },
  ]

  it('returns the next viewport min-width prefixed with <', () => {
    expect(getViewportRangeLabel(viewports[0], viewports)).toBe('<576')
    expect(getViewportRangeLabel(viewports[1], viewports)).toBe('<768')
    expect(getViewportRangeLabel(viewports[2], viewports)).toBe('<1400')
  })

  it('returns null for the largest viewport', () => {
    expect(getViewportRangeLabel(viewports[3], viewports)).toBeNull()
  })

  it('returns null for a viewport not in the list', () => {
    const stranger: ViewportConfig = { key: 'unknown', label: '?', minWidth: 0 }
    expect(getViewportRangeLabel(stranger, viewports)).toBeNull()
  })
})
