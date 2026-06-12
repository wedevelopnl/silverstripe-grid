import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import { getElementType } from './getElementType'

beforeEach(() => {
  resetIdCounter()
})

describe('getElementType', () => {
  it('returns containerType for container nodes', () => {
    expect(getElementType(createSectionNode())).toBe('section')
    expect(getElementType(createRowNode())).toBe('row')
    expect(getElementType(createColumnNode())).toBe('column')
  })

  it('returns "element" for simple element nodes', () => {
    expect(getElementType(createSimpleElement())).toBe('element')
  })
})
