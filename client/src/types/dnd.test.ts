import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import {
  buildDraggableId,
  getDraggableType,
  getDraggableTypeForNode,
  parseDraggableId,
} from './dnd'

beforeEach(() => {
  resetIdCounter()
})

describe('buildDraggableId', () => {
  it('produces a composite type-id string', () => {
    expect(buildDraggableId('section', 42)).toBe('section-42')
    expect(buildDraggableId('row', 1)).toBe('row-1')
    expect(buildDraggableId('column', 99)).toBe('column-99')
    expect(buildDraggableId('element', 7)).toBe('element-7')
  })
})

describe('parseDraggableId', () => {
  it('round-trips with buildDraggableId', () => {
    const result = parseDraggableId(buildDraggableId('row', 5))
    expect(result).toEqual({ type: 'row', id: 5, key: 'row-5' })
  })

  it('returns null for empty string', () => {
    expect(parseDraggableId('')).toBeNull()
  })

  it('returns null for string without separator', () => {
    expect(parseDraggableId('section')).toBeNull()
  })

  it('returns null for unknown type prefix', () => {
    expect(parseDraggableId('unknown-5')).toBeNull()
  })

  it('returns null for non-numeric id', () => {
    expect(parseDraggableId('row-abc')).toBeNull()
  })

  it('returns null for zero id', () => {
    expect(parseDraggableId('row-0')).toBeNull()
  })

  it('returns null for negative id', () => {
    expect(parseDraggableId('row--1')).toBeNull()
  })

  it('returns null for floating point id', () => {
    expect(parseDraggableId('row-1.5')).toBeNull()
  })

  it('returns null when separator is first character', () => {
    expect(parseDraggableId('-5')).toBeNull()
  })

  it('returns null for a page key — a valid NodeKey type that is not draggable', () => {
    // 'page-5' parses as a structurally valid NodeKey (NodeIdentity.fromKey
    // accepts it), so the only thing rejecting it is the `value !== 'page'`
    // guard in isDraggableType. A mutant turning that guard into `true` would
    // wrongly accept pages as draggable.
    expect(parseDraggableId('page-5')).toBeNull()
  })
})

describe('getDraggableType', () => {
  it('extracts type from valid composite id', () => {
    expect(getDraggableType('column-10')).toBe('column')
  })

  it('returns null for invalid id', () => {
    expect(getDraggableType('root')).toBeNull()
  })
})

describe('getDraggableTypeForNode', () => {
  it('returns containerType for container nodes', () => {
    expect(getDraggableTypeForNode(createSectionNode())).toBe('section')
    expect(getDraggableTypeForNode(createRowNode())).toBe('row')
    expect(getDraggableTypeForNode(createColumnNode())).toBe('column')
  })

  it('returns "element" for simple element nodes', () => {
    expect(getDraggableTypeForNode(createSimpleElement())).toBe('element')
  })
})
