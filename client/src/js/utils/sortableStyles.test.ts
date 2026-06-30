import { describe, expect, it } from 'vitest'
import { buildSortableStyle } from './sortableStyles'

describe('buildSortableStyle', () => {
  it('returns transform and transition from input', () => {
    const transform = { x: 10, y: 20, scaleX: 1, scaleY: 1 }
    const style = buildSortableStyle(transform, 'transform 200ms ease', false)

    expect(style.transform).toContain('translate3d(10px, 20px, 0)')
    expect(style.transition).toBe('transform 200ms ease')
    expect(style.opacity).toBeUndefined()
  })

  it('sets opacity to 0.3 when dragging', () => {
    const style = buildSortableStyle(null, undefined, true)
    expect(style.opacity).toBe(0.3)
  })

  it('returns null transform for null input', () => {
    const style = buildSortableStyle(null, undefined, false)
    expect(style.transform).toBeUndefined()
    expect(style.transition).toBeUndefined()
    expect(style.opacity).toBeUndefined()
  })
})
