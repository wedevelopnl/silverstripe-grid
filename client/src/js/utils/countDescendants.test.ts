import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import { countDescendants } from './countDescendants'

beforeEach(() => {
  resetIdCounter()
})

describe('countDescendants', () => {
  it('returns 0 for a simple element node', () => {
    expect(countDescendants(createSimpleElement())).toBe(0)
  })

  it('returns 0 for a container with null children', () => {
    expect(countDescendants(createColumnNode({ children: null }))).toBe(0)
  })

  it('counts direct children of a container', () => {
    const column = createColumnNode({ childCount: 3 })
    expect(countDescendants(column)).toBe(3)
  })

  it('counts all nested descendants recursively', () => {
    // Section → 1 Row → 1 Column → 1 Element = 3 descendants
    const section = createSectionNode()
    expect(countDescendants(section)).toBe(3)
  })

  it('counts across multiple branches', () => {
    // Section → 2 Rows, each → 1 Column → 1 Element = 2 + 2 + 2 = 6
    const section = createSectionNode({ rowCount: 2 })
    expect(countDescendants(section)).toBe(6)
  })

  it('returns 0 for an empty container', () => {
    const row = createRowNode({ children: [] })
    expect(countDescendants(row)).toBe(0)
  })
})
