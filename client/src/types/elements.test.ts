import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import {
  isColumnNode,
  isContainerNode,
  isRowNode,
  isSectionNode,
  isSimpleElementNode,
} from './elements'

beforeEach(() => {
  resetIdCounter()
})

describe('isContainerNode', () => {
  it('returns true for section, row, and column nodes', () => {
    expect(isContainerNode(createSectionNode())).toBe(true)
    expect(isContainerNode(createRowNode())).toBe(true)
    expect(isContainerNode(createColumnNode())).toBe(true)
  })

  it('returns false for simple element nodes', () => {
    expect(isContainerNode(createSimpleElement())).toBe(false)
  })
})

describe('isSectionNode', () => {
  it('returns true only for section nodes', () => {
    expect(isSectionNode(createSectionNode())).toBe(true)
    expect(isSectionNode(createRowNode())).toBe(false)
    expect(isSectionNode(createColumnNode())).toBe(false)
    expect(isSectionNode(createSimpleElement())).toBe(false)
  })
})

describe('isRowNode', () => {
  it('returns true only for row nodes', () => {
    expect(isRowNode(createRowNode())).toBe(true)
    expect(isRowNode(createSectionNode())).toBe(false)
    expect(isRowNode(createColumnNode())).toBe(false)
    expect(isRowNode(createSimpleElement())).toBe(false)
  })
})

describe('isColumnNode', () => {
  it('returns true only for column nodes', () => {
    expect(isColumnNode(createColumnNode())).toBe(true)
    expect(isColumnNode(createSectionNode())).toBe(false)
    expect(isColumnNode(createRowNode())).toBe(false)
    expect(isColumnNode(createSimpleElement())).toBe(false)
  })
})

describe('isSimpleElementNode', () => {
  it('returns true only for simple element nodes', () => {
    expect(isSimpleElementNode(createSimpleElement())).toBe(true)
    expect(isSimpleElementNode(createSectionNode())).toBe(false)
    expect(isSimpleElementNode(createRowNode())).toBe(false)
    expect(isSimpleElementNode(createColumnNode())).toBe(false)
  })
})
