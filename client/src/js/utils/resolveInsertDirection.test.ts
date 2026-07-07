import { describe, expect, it } from 'vitest'
import { resolveInsertDirection } from './resolveInsertDirection'

describe('resolveInsertDirection', () => {
  // Center: x = 200, y = 250.
  const rect = { left: 100, top: 200, width: 200, height: 100 }

  describe('Y-axis', () => {
    it.each([
      ['above center → before', { x: 200, y: 220 }, 'before'],
      ['below center → after', { x: 200, y: 280 }, 'after'],
      ['exactly at center → after', { x: 200, y: 250 }, 'after'],
      ['X position is ignored', { x: 120, y: 220 }, 'before'],
    ] as const)('%s', (_label, pointer, expected) => {
      expect(resolveInsertDirection(pointer, rect, 'y')).toBe(expected)
    })
  })

  describe('X-axis', () => {
    it.each([
      ['left of center → before', { x: 150, y: 250 }, 'before'],
      ['right of center → after', { x: 250, y: 250 }, 'after'],
      ['exactly at center → after', { x: 200, y: 250 }, 'after'],
      ['Y position is ignored', { x: 150, y: 290 }, 'before'],
    ] as const)('%s', (_label, pointer, expected) => {
      expect(resolveInsertDirection(pointer, rect, 'x')).toBe(expected)
    })
  })
})
