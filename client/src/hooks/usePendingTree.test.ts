import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  createColumnNode,
  createParsedDraggableId,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  createTreeApiResponse,
  resetIdCounter,
} from '@/testing/factories'
import type { TreeApiResponse } from '@/types/elements'
import { NodeIdentity } from '@/types/identity'
import { buildMaps } from './useElementMaps'
import { usePendingTree } from './usePendingTree'

beforeEach(() => {
  resetIdCounter()
})

/**
 * Build a tree with two columns under one row: col 10 has one element, col 20
 * is empty. The root page id is 1.
 */
function buildTwoColumnTree(pageId = 1) {
  const element = createSimpleElement({ id: 5, parent: { type: 'column', id: 10 } })
  const col1 = createColumnNode({
    id: 10,
    parent: { type: 'row', id: 100 },
    children: [element],
  })
  const col2 = createColumnNode({
    id: 20,
    parent: { type: 'row', id: 100 },
    children: [],
  })
  const row = createRowNode({
    id: 100,
    parent: { type: 'section', id: 1000 },
    children: [col1, col2],
  })
  const section = createSectionNode({
    id: 1000,
    parent: { type: 'page', id: pageId },
    children: [row],
  })
  const tree = createTreeApiResponse({ pageId, sections: [section] })
  return { tree, element, col1, col2 }
}

describe('usePendingTree', () => {
  it('initially returns pendingTree as null', () => {
    const { result } = renderHook(() => usePendingTree())
    expect(result.current.pendingTree).toBeNull()
  })

  it('initially has hasPendingMoveRef set to false', () => {
    const { result } = renderHook(() => usePendingTree())
    expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(false)
  })

  describe('applyPendingMove', () => {
    it('sets pending tree and returns new tree/maps for a cross-container move', () => {
      const { tree, element } = buildTwoColumnTree()

      const { result } = renderHook(() => usePendingTree())

      let moveResult: ReturnType<typeof result.current.applyPendingMove> = null
      act(() => {
        moveResult = result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })

      expect(moveResult).not.toBeNull()
      expect(result.current.pendingTree).not.toBeNull()

      const newMaps = moveResult!.maps
      const col2Children = newMaps.childrenByParentKey.get(NodeIdentity.toKey('column', 20))
      expect(col2Children).toHaveLength(1)
      expect(col2Children?.[0].self.id).toBe(element.self.id)

      const col1Children = newMaps.childrenByParentKey.get(NodeIdentity.toKey('column', 10))
      expect(col1Children).toHaveLength(0)
    })

    it('sets hasPendingMoveRef to true', () => {
      const { tree, element } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })

      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true)
    })

    it('updates pendingContainerItemsRef with target siblings', () => {
      const { tree, element } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })

      const pendingItems = result.current.collisionRefs.pendingContainerItemsRef.current
      expect(pendingItems).not.toBeNull()
      expect(pendingItems?.size).toBe(1)
      expect(pendingItems?.has(`element-${element.self.id}`)).toBe(true)
    })

    it('returns null for a no-op move (same position)', () => {
      const { tree } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      let moveResult: ReturnType<typeof result.current.applyPendingMove> = null
      act(() => {
        moveResult = result.current.applyPendingMove(
          createParsedDraggableId('element', 5),
          NodeIdentity.toKey('column', 10),
          null,
          tree,
        )
      })

      expect(moveResult).toBeNull()
      expect(result.current.pendingTree).toBeNull()
    })

    it('clears a stale overRectRef snapshot when entering the pending path', () => {
      const { tree, element } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      // Simulate a tier-1 (same-container) snapshot captured before the
      // cross-container transition.
      act(() => {
        result.current.collisionRefs.overRectRef.current = {
          id: 'element-5',
          nodeRef: { current: null },
        }
      })

      act(() => {
        result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })

      expect(result.current.collisionRefs.overRectRef.current).toBeNull()
    })

    it('clears overRectRef even for a no-op move (same position)', () => {
      const { tree } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.collisionRefs.overRectRef.current = {
          id: 'element-5',
          nodeRef: { current: null },
        }
      })

      act(() => {
        result.current.applyPendingMove(
          createParsedDraggableId('element', 5),
          NodeIdentity.toKey('column', 10),
          null,
          tree,
        )
      })

      expect(result.current.collisionRefs.overRectRef.current).toBeNull()
    })
  })

  describe('getEffective', () => {
    it('returns canonical tree when no pending tree', () => {
      const { tree } = buildTwoColumnTree()
      const maps = buildMaps(tree)

      const { result } = renderHook(() => usePendingTree())
      const effective = result.current.getEffective(tree, maps)
      expect(effective.tree).toBe(tree)
      expect(effective.maps).toBe(maps)
    })

    it('returns pending tree when one is set', () => {
      const { tree, element } = buildTwoColumnTree()

      const canonicalTree: TreeApiResponse = {
        rootParent: { type: 'page', id: 99 },
        nodes: [],
      }
      const canonicalMaps = buildMaps(canonicalTree)

      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })

      const effective = result.current.getEffective(canonicalTree, canonicalMaps)
      expect(effective.tree).not.toBe(canonicalTree)
      expect(effective.maps).not.toBe(canonicalMaps)
    })
  })

  describe('clear', () => {
    it('resets pendingTree and collision refs', () => {
      const { tree, element } = buildTwoColumnTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.setSourceSiblings(new Set(['element-1']))
        result.current.applyPendingMove(
          createParsedDraggableId('element', element.self.id),
          NodeIdentity.toKey('column', 20),
          null,
          tree,
        )
      })
      expect(result.current.pendingTree).not.toBeNull()

      act(() => {
        result.current.clear()
      })

      expect(result.current.pendingTree).toBeNull()
      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(false)
      expect(result.current.collisionRefs.pendingContainerItemsRef.current).toBeNull()
      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBeNull()
      expect(result.current.collisionRefs.overRectRef.current).toBeNull()
    })
  })

  describe('setSourceSiblings', () => {
    it('stores siblings in sourceContainerItemsRef', () => {
      const { result } = renderHook(() => usePendingTree())

      const siblings = new Set<string | number>(['element-1', 'element-2'])
      act(() => {
        result.current.setSourceSiblings(siblings)
      })

      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBe(siblings)
    })
  })
})
