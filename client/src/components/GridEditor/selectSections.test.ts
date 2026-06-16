import { describe, expect, it } from 'vitest'
import { createSectionNode, createTreeApiResponse, resetIdCounter } from '@/testing/factories'
import { selectSections } from './selectSections'

describe('selectSections', () => {
  it('returns an empty array when the tree is undefined', () => {
    expect(selectSections(undefined)).toEqual([])
  })

  it('returns only the section nodes from the tree', () => {
    resetIdCounter()
    const tree = createTreeApiResponse({
      pageId: 1,
      sections: [
        createSectionNode({ id: 10, parent: { type: 'page', id: 1 }, title: 'Hero' }),
        createSectionNode({ id: 20, parent: { type: 'page', id: 1 }, title: 'Content' }),
      ],
    })
    const result = selectSections(tree)
    expect(result).toHaveLength(2)
    expect(result.map((s) => s.title)).toEqual(['Hero', 'Content'])
  })
})
