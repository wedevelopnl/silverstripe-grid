import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
} from '@/testing/factories'
import { hasUnpublishedDescendant, isUnpublished } from './publishStatus'

describe('isUnpublished()', () => {
  it('counts work that has never been published', () => {
    expect(isUnpublished('draft')).toBe(true)
  })

  it('counts work that was published and has since changed', () => {
    expect(isUnpublished('modified')).toBe(true)
  })

  it('does not count a published element', () => {
    expect(isUnpublished('published')).toBe(false)
  })

  // `removed` means live-only; such elements never appear in the editor tree,
  // so a mark for them could not be seen anyway.
  it('does not count a removed element', () => {
    expect(isUnpublished('removed')).toBe(false)
  })
})

describe('hasUnpublishedDescendant()', () => {
  it('returns false for a leaf element, which can hold nothing', () => {
    expect(hasUnpublishedDescendant(createSimpleElement({ status: 'modified' }))).toBe(false)
  })

  it('ignores the node’s own status', () => {
    const column = createColumnNode({ status: 'modified', children: [] })

    expect(hasUnpublishedDescendant(column)).toBe(false)
  })

  it('finds a modified direct child', () => {
    const column = createColumnNode({
      children: [
        createSimpleElement({ status: 'published' }),
        createSimpleElement({ status: 'modified' }),
      ],
    })

    expect(hasUnpublishedDescendant(column)).toBe(true)
  })

  it('finds a draft direct child', () => {
    const column = createColumnNode({
      children: [
        createSimpleElement({ status: 'published' }),
        createSimpleElement({ status: 'draft' }),
      ],
    })

    expect(hasUnpublishedDescendant(column)).toBe(true)
  })

  it('finds an unpublished block three levels down', () => {
    const section = createSectionNode({
      children: [
        createRowNode({
          children: [
            createColumnNode({
              children: [createSimpleElement({ status: 'draft' })],
            }),
          ],
        }),
      ],
    })

    expect(hasUnpublishedDescendant(section)).toBe(true)
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

    expect(hasUnpublishedDescendant(section)).toBe(false)
  })

  it('does not treat a removed descendant as unpublished', () => {
    const column = createColumnNode({
      children: [createSimpleElement({ status: 'removed' })],
    })

    expect(hasUnpublishedDescendant(column)).toBe(false)
  })

  it('treats an unloaded (null) child list as holding nothing', () => {
    expect(hasUnpublishedDescendant(createColumnNode({ children: null }))).toBe(false)
  })

  it('finds an unpublished container even when its own children are clean', () => {
    const section = createSectionNode({
      children: [createRowNode({ status: 'draft', children: [] })],
    })

    expect(hasUnpublishedDescendant(section)).toBe(true)
  })
})
