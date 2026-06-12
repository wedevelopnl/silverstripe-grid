import { describe, it, expect } from 'vitest'
import { getElementType } from './getElementType'
import {
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'

beforeEach(() => {
  resetIdCounter()
})

describe('getElementType', () => {
  it.each([
    { name: 'section', create: createSectionNode, expected: 'section' },
    { name: 'row', create: createRowNode, expected: 'row' },
    { name: 'column', create: createColumnNode, expected: 'column' },
  ])('returns containerType "$expected" for a $name container node', ({ create, expected }) => {
    expect(getElementType(create())).toBe(expected)
  })

  it('returns "element" for simple element nodes', () => {
    expect(getElementType(createSimpleElement())).toBe('element')
  })
})
