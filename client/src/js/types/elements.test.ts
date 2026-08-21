import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSharedBlockReferenceNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import type { ElementNode, SectionNode } from './elements'
import {
  isColumnNode,
  isContainerNode,
  isInsideSharedBlock,
  isRowNode,
  isSectionNode,
  isSharedBlockReferenceNode,
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

describe('shared block placement guards', () => {
  const reference = createSharedBlockReferenceNode({ root: createSectionNode() })

  it('identifies a placement', () => {
    expect(isSharedBlockReferenceNode(reference)).toBe(true)
  })

  it('does not treat a placement as a container or a simple element', () => {
    expect(isContainerNode(reference)).toBe(false)
    expect(isSimpleElementNode(reference)).toBe(false)
  })

  it('does not mistake a plain leaf for a placement', () => {
    expect(isSharedBlockReferenceNode(createSimpleElement())).toBe(false)
  })

  it('reports the placement itself as outside the shared block', () => {
    expect(isInsideSharedBlock(reference)).toBe(false)
  })

  it('reports the block root and its descendants as inside the shared block', () => {
    const root = reference.children[0]
    expect(root).toBeDefined()
    expect(isInsideSharedBlock(root as ElementNode)).toBe(true)

    const row = (root as SectionNode).children?.[0]
    expect(row).toBeDefined()
    expect(isInsideSharedBlock(row as ElementNode)).toBe(true)
  })

  it('reports page-local content as outside any shared block', () => {
    expect(isInsideSharedBlock(createSectionNode())).toBe(false)
  })
})
