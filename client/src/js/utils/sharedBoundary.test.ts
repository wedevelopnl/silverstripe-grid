import { beforeEach, describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSharedBlockReferenceNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import { buildMaps } from '@/hooks/useElementMaps'
import type { ElementNode } from '@/types/elements'
import type { NodeKey } from '@/types/identity'
import { filterParentContainers, filterSiblings, sameSharedContext } from './collisionDetection'

beforeEach(() => {
  resetIdCounter()
})

/** Minimal droppable stand-ins — the filters only read `id`. */
function droppables(...ids: string[]) {
  return ids.map((id) => ({ id }) as unknown as Parameters<typeof filterSiblings>[1][number])
}

function mapOf(...entries: [string, Partial<ElementNode>][]) {
  return new Map(entries.map(([key, node]) => [key as NodeKey, node as ElementNode]))
}

describe('sameSharedContext', () => {
  const nodeMap = mapOf(
    ['section-1', { sharedBlockKey: undefined }],
    ['section-2', { sharedBlockKey: undefined }],
    ['section-3', { sharedBlockKey: 'element-9' as NodeKey }],
    ['section-4', { sharedBlockKey: 'element-9' as NodeKey }],
    ['section-5', { sharedBlockKey: 'element-8' as NodeKey }],
  )

  it.each([
    ['both page-local', 'section-1', 'section-2', true],
    ['both inside the same block', 'section-3', 'section-4', true],
    ['page-local into a block', 'section-1', 'section-3', false],
    ['block content out to the page', 'section-3', 'section-1', false],
    ['between two different blocks', 'section-3', 'section-5', false],
  ] as const)('%s → %s', (_label, active, candidate, expected) => {
    expect(sameSharedContext(active, candidate, nodeMap)).toBe(expected)
  })

  it('is inert without a node map, preserving pre-shared-block behaviour', () => {
    expect(sameSharedContext('section-3', 'section-1', undefined)).toBe(true)
  })

  it('treats an unknown droppable as page-local', () => {
    // Page-level droppables have unparseable ids and never enter the map.
    expect(sameSharedContext('section-1', 'grid-editor-canvas', nodeMap)).toBe(true)
    expect(sameSharedContext('section-3', 'grid-editor-canvas', nodeMap)).toBe(false)
  })
})

describe('filterSiblings across the shared boundary', () => {
  const nodeMap = mapOf(
    ['section-1', { sharedBlockKey: undefined }],
    ['section-2', { sharedBlockKey: undefined }],
    ['section-3', { sharedBlockKey: 'element-9' as NodeKey }],
  )

  it('drops a same-type sibling that lives inside a block', () => {
    const kept = filterSiblings('section-1', droppables('section-2', 'section-3'), nodeMap)

    expect(kept.map((c) => c.id)).toEqual(['section-2'])
  })

  it('drops page-local siblings while dragging block content', () => {
    const kept = filterSiblings('section-3', droppables('section-1', 'section-2'), nodeMap)

    expect(kept).toEqual([])
  })

  it('keeps siblings within the same block', () => {
    const sameBlock = mapOf(
      ['row-1', { sharedBlockKey: 'element-9' as NodeKey }],
      ['row-2', { sharedBlockKey: 'element-9' as NodeKey }],
    )

    expect(filterSiblings('row-1', droppables('row-2'), sameBlock).map((c) => c.id)).toEqual([
      'row-2',
    ])
  })
})

describe('filterParentContainers across the shared boundary', () => {
  const nodeMap = mapOf(
    ['element-1', { sharedBlockKey: undefined }],
    ['column-2', { sharedBlockKey: undefined }],
    ['column-3', { sharedBlockKey: 'element-9' as NodeKey }],
    ['element-4', { sharedBlockKey: 'element-9' as NodeKey }],
  )

  it('excludes a shared column while dragging local content', () => {
    const kept = filterParentContainers('element-1', droppables('column-2', 'column-3'), nodeMap)

    expect(kept.map((c) => c.id)).toEqual(['column-2'])
  })

  it('excludes local columns while dragging shared content', () => {
    const kept = filterParentContainers('element-4', droppables('column-2', 'column-3'), nodeMap)

    expect(kept.map((c) => c.id)).toEqual(['column-3'])
  })
})

describe('buildMaps with a shared block placement', () => {
  it('indexes the placement and everything beneath it', () => {
    const leaf = createSimpleElement({ id: 40 })
    const column = createColumnNode({ id: 30, children: [leaf] })
    const row = createRowNode({ id: 20, children: [column] })
    const section = createSectionNode({ id: 10, children: [row] })
    const placement = createSharedBlockReferenceNode({
      id: 5,
      parent: { type: 'page', id: 1 },
      root: section,
    })

    const maps = buildMaps({ rootParent: { type: 'page', id: 1 }, nodes: [placement] })

    // The placement itself, and the whole subtree hanging off it.
    expect(maps.nodeMap.has(placement.nodeKey)).toBe(true)
    for (const key of ['section-10', 'row-20', 'column-30', 'element-40'] as NodeKey[]) {
      expect(maps.nodeMap.has(key)).toBe(true)
    }

    // The placement's children list is its single root child.
    expect(maps.childrenByParentKey.get(placement.nodeKey)?.map((n) => n.nodeKey)).toEqual([
      'section-10',
    ])
  })

  it('carries sharedBlockKey onto the indexed descendants', () => {
    const placement = createSharedBlockReferenceNode({
      id: 5,
      parent: { type: 'page', id: 1 },
      root: createSectionNode({ id: 10, children: [createRowNode({ id: 20, children: [] })] }),
    })

    const maps = buildMaps({ rootParent: { type: 'page', id: 1 }, nodes: [placement] })

    expect(maps.nodeMap.get('section-10' as NodeKey)?.sharedBlockKey).toBe(placement.nodeKey)
    expect(maps.nodeMap.get('row-20' as NodeKey)?.sharedBlockKey).toBe(placement.nodeKey)
    expect(maps.nodeMap.get(placement.nodeKey)?.sharedBlockKey).toBeUndefined()
  })
})
