import { afterEach, describe, expect, it, vi } from 'vitest'
import { resolveDropAxis } from './resolveDropAxis'

function mockRect(width: number): DOMRect {
  return {
    width,
    height: 50,
    top: 0,
    left: 0,
    right: width,
    bottom: 50,
    x: 0,
    y: 0,
    toJSON: () => ({}),
  } as DOMRect
}

interface BuildOptions {
  targetWidth: number
  containerWidth: number
  marked?: boolean
  paddingX?: number
}

/**
 * Build container > target in jsdom with mocked rects. jsdom returns
 * all-zero rects, so getBoundingClientRect is stubbed per element;
 * padding goes through a real inline style so getComputedStyle sees it.
 */
function buildTarget({
  targetWidth,
  containerWidth,
  marked = true,
  paddingX = 0,
}: BuildOptions): HTMLElement {
  const container = document.createElement('div')
  if (marked) container.setAttribute('data-dnd-container', '')
  if (paddingX > 0) {
    container.style.paddingLeft = `${paddingX}px`
    container.style.paddingRight = `${paddingX}px`
  }
  const target = document.createElement('div')
  container.appendChild(target)
  document.body.appendChild(container)
  vi.spyOn(container, 'getBoundingClientRect').mockReturnValue(mockRect(containerWidth))
  vi.spyOn(target, 'getBoundingClientRect').mockReturnValue(mockRect(targetWidth))
  return target
}

afterEach(() => {
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

describe('resolveDropAxis', () => {
  describe('geometric rule (marked ancestor present)', () => {
    it.each([
      ['full-width column (12/12)', 1000, 1000, 'y'],
      ['11/12 column stays horizontal', 917, 1000, 'x'],
      ['exactly at the threshold', 950, 1000, 'y'],
      ['just below the threshold', 949, 1000, 'x'],
      ['half-width column', 500, 1000, 'x'],
    ] as const)('%s → %s', (_label, targetWidth, containerWidth, expected) => {
      const target = buildTarget({ targetWidth, containerWidth })
      expect(resolveDropAxis(target, 'column')).toBe(expected)
    })

    it.each(['section', 'row', 'element'] as const)(
      'full-width %s resolves to y geometrically',
      (type) => {
        const target = buildTarget({ targetWidth: 1000, containerWidth: 1000 })
        expect(resolveDropAxis(target, type)).toBe('y')
      },
    )

    it('measures against the container content box (padding excluded)', () => {
      // 1000px container minus 2×30px padding = 940px content; a 940px
      // target is full-width even though 940/1000 < FULL_WIDTH_RATIO.
      const target = buildTarget({ targetWidth: 940, containerWidth: 1000, paddingX: 30 })
      expect(resolveDropAxis(target, 'column')).toBe('y')
    })

    it('subtracts BOTH left and right padding from the content box', () => {
      // 1000px container, 100px padding per side, 800px target.
      //   Correct content box = 1000 − 100 − 100 = 800 → 800/800 = 1.0 ≥ 0.95 → 'y'.
      // Dropping EITHER side's subtraction (the `|| 0` → `&& 0` mutant makes a
      // real numeric padding evaluate to 0) leaves content = 1000 − 0 − 100 = 900
      // → 800/900 = 0.889 < 0.95 → 'x'. The single-sided asymmetry is what the
      // existing 30px-symmetric case cannot expose (970 content still clears 0.95).
      const target = buildTarget({ targetWidth: 800, containerWidth: 1000, paddingX: 100 })
      expect(resolveDropAxis(target, 'column')).toBe('y')
    })
  })

  describe('degraded mode (legacy type rule)', () => {
    it.each([
      ['column', 'x'],
      ['section', 'y'],
      ['row', 'y'],
      ['element', 'y'],
    ] as const)('null node: %s → %s', (type, expected) => {
      expect(resolveDropAxis(null, type)).toBe(expected)
    })

    it('falls back when no marked ancestor exists', () => {
      const target = buildTarget({ targetWidth: 1000, containerWidth: 1000, marked: false })
      expect(resolveDropAxis(target, 'column')).toBe('x')
    })

    it('falls back when the container content width is zero', () => {
      const target = buildTarget({ targetWidth: 0, containerWidth: 0 })
      expect(resolveDropAxis(target, 'section')).toBe('y')
    })
  })
})
