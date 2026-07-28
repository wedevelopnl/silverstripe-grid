import { describe, expect, it } from 'vitest'
import { getViewportIcon } from './viewportIcon'

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
