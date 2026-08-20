import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  heightForWidth,
  installViewportStyles,
  removeViewportStyles,
  STYLE_TAG_ID,
} from './viewportPreviewStyles'

vi.mock('@/utils/gridAdapter', async () =>
  (await import('@/testing/mockGridAdapter')).mockGridAdapterModule(),
)

describe('heightForWidth', () => {
  it('clamps widths below 500 to the 500px floor', () => {
    expect(heightForWidth(375)).toBe(500)
    expect(heightForWidth(0)).toBe(500)
  })

  it('clamps widths above 1200 to the 900px cap', () => {
    expect(heightForWidth(1400)).toBe(900)
    expect(heightForWidth(2000)).toBe(900)
  })

  it('uses a 0.75 factor inside the clamp range', () => {
    expect(heightForWidth(768)).toBe(576)
    expect(heightForWidth(992)).toBe(744)
  })

  it('is monotonic across adapter widths', () => {
    const widths = [375, 576, 768, 992, 1200, 1400]
    const heights = widths.map(heightForWidth)
    for (let i = 1; i < heights.length; i++) {
      expect(heights[i]).toBeGreaterThanOrEqual(heights[i - 1])
    }
  })
})

describe('installViewportStyles', () => {
  afterEach(() => {
    removeViewportStyles()
  })

  it('inserts a <style> tag with one rule block per adapter viewport', () => {
    installViewportStyles()

    const tag = document.getElementById(STYLE_TAG_ID) as HTMLStyleElement | null
    expect(tag).not.toBeNull()
    const content = tag!.textContent ?? ''

    // xs (minWidth 0 → 375 mobile-first fallback), floored to 500 height
    expect(content).toContain('.cms-preview.grid-xs .preview-device-outer')
    expect(content).toContain('width: 375px')
    expect(content).toMatch(/grid-xs[^}]*height: 500px/s)

    // sm (576 → 500 floor)
    expect(content).toMatch(/grid-sm[^}]*height: 500px/s)

    // md (768 → 576)
    expect(content).toContain('width: 768px')
    expect(content).toMatch(/grid-md[^}]*height: 576px/s)

    // Dimension readout on the ::after pseudo
    expect(content).toContain('375px × 500px')
    expect(content).toContain('768px × 576px')
  })

  it('is idempotent — second call replaces content, only one tag remains', () => {
    installViewportStyles()
    installViewportStyles()

    expect(document.querySelectorAll(`#${STYLE_TAG_ID}`)).toHaveLength(1)
  })
})

describe('removeViewportStyles', () => {
  it('removes the installed tag', () => {
    installViewportStyles()
    expect(document.getElementById(STYLE_TAG_ID)).not.toBeNull()

    removeViewportStyles()

    expect(document.getElementById(STYLE_TAG_ID)).toBeNull()
  })

  it('is a no-op when no tag is installed', () => {
    expect(() => removeViewportStyles()).not.toThrow()
  })
})
