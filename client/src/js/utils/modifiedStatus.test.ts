import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
} from '@/testing/factories'
import { hasModifiedDescendant } from './modifiedStatus'

describe('hasModifiedDescendant()', () => {
  it('returns false for a leaf element, which can hold nothing', () => {
    expect(hasModifiedDescendant(createSimpleElement({ status: 'modified' }))).toBe(false)
  })

  it('ignores the node’s own modified status', () => {
    const column = createColumnNode({ status: 'modified', children: [] })

    expect(hasModifiedDescendant(column)).toBe(false)
  })

  it('finds a modified direct child', () => {
    const column = createColumnNode({
      children: [
        createSimpleElement({ status: 'published' }),
        createSimpleElement({ status: 'modified' }),
      ],
    })

    expect(hasModifiedDescendant(column)).toBe(true)
  })

  it('finds a modified block three levels down', () => {
    const section = createSectionNode({
      children: [
        createRowNode({
          children: [
            createColumnNode({
              children: [createSimpleElement({ status: 'modified' })],
            }),
          ],
        }),
      ],
    })

    expect(hasModifiedDescendant(section)).toBe(true)
  })

  it('returns false when every descendant is published', () => {
    const section = createSectionNode({
      children: [
        createRowNode({
          children: [
            createColumnNode({ children: [createSimpleElement({ status: 'published' })] }),
          ],
        }),
      ],
    })

    expect(hasModifiedDescendant(section)).toBe(false)
  })

  it('does not treat draft or removed descendants as modified', () => {
    const column = createColumnNode({
      children: [
        createSimpleElement({ status: 'draft' }),
        createSimpleElement({ status: 'removed' }),
      ],
    })

    expect(hasModifiedDescendant(column)).toBe(false)
  })

  it('treats an unloaded (null) child list as holding nothing', () => {
    expect(hasModifiedDescendant(createColumnNode({ children: null }))).toBe(false)
  })

  it('finds a modified container even when its own children are clean', () => {
    const section = createSectionNode({
      children: [createRowNode({ status: 'modified', children: [] })],
    })

    expect(hasModifiedDescendant(section)).toBe(true)
  })
})
