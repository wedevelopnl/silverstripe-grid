import { describe, expect, it } from 'vitest'
import { resolveInsertDirection } from './resolveInsertDirection'

describe('resolveInsertDirection', () => {
  const rect = { left: 100, top: 200, width: 200, height: 100 }

  describe('non-column types (Y-axis)', () => {
    it('returns "before" when pointer is above center', () => {
      expect(resolveInsertDirection({ x: 200, y: 220 }, rect, 'row')).toBe('before')
    })

    it('returns "after" when pointer is below center', () => {
      expect(resolveInsertDirection({ x: 200, y: 280 }, rect, 'section')).toBe('after')
    })

    it('returns "after" when pointer is exactly at center', () => {
      expect(resolveInsertDirection({ x: 200, y: 250 }, rect, 'element')).toBe('after')
    })
  })

  describe('column type (X-axis)', () => {
    it('returns "before" when pointer is left of center', () => {
      expect(resolveInsertDirection({ x: 150, y: 250 }, rect, 'column')).toBe('before')
    })

    it('returns "after" when pointer is right of center', () => {
      expect(resolveInsertDirection({ x: 250, y: 250 }, rect, 'column')).toBe('after')
    })

    it('returns "after" when pointer is exactly at center', () => {
      expect(resolveInsertDirection({ x: 200, y: 250 }, rect, 'column')).toBe('after')
    })
  })
})
