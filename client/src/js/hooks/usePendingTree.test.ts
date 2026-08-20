import { act, renderHook } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  buildTree,
  createParsedDraggableId,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories'
import type { TreeApiResponse } from '@/types/elements'
import { NodeIdentity, type NodeKey } from '@/types/identity'
import { buildMaps } from './useElementMaps'
import { usePendingTree } from './usePendingTree'

beforeEach(() => {
  resetIdCounter()
})

/**
 * Fixture: section 1000 → row 100 → column 10 (holding element 5) and
 * column 20 (empty). The root page id defaults to 1.
 */
function buildFixtureTree(pageId = 1) {
  const element = createSimpleElement({ id: 5, parent: { type: 'column', id: 10 } })
  const { tree } = buildTree({
    pageId,
    sectionId: 1000,
    rows: [
      {
        id: 100,
        columns: [
          { id: 10, children: [element] },
          { id: 20, children: [] },
        ],
      },
    ],
  })
  return { tree, element }
}

/** Wrap act + parsed-id + map building around applyPendingMove. */
function move(
  result: { current: ReturnType<typeof usePendingTree> },
  tree: TreeApiResponse,
  targetKey: NodeKey = NodeIdentity.toKey('column', 20),
  after: number | null = null,
  elementId = 5,
) {
  act(() => {
    result.current.applyPendingMove(
      createParsedDraggableId('element', elementId),
      targetKey,
      after,
      tree,
      buildMaps(tree),
    )
  })
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
    it('sets pending tree for a cross-container move', () => {
      const { tree, element } = buildFixtureTree()
      const canonicalMaps = buildMaps(tree)

      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      expect(result.current.pendingTree).not.toBeNull()

      const { maps: newMaps } = result.current.getEffective(tree, canonicalMaps)
      const col2Children = newMaps.childrenByParentKey.get(NodeIdentity.toKey('column', 20))
      expect(col2Children).toHaveLength(1)
      expect(col2Children?.[0].self.id).toBe(element.self.id)

      const col1Children = newMaps.childrenByParentKey.get(NodeIdentity.toKey('column', 10))
      expect(col1Children).toHaveLength(0)
    })

    it('sets hasPendingMoveRef to true', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true)
    })

    it('updates pendingContainerItemsRef with target siblings', () => {
      const { tree, element } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      const pendingItems = result.current.collisionRefs.pendingContainerItemsRef.current
      expect(pendingItems).not.toBeNull()
      expect(pendingItems?.size).toBe(1)
      expect(pendingItems?.has(`element-${element.self.id}`)).toBe(true)
    })

    it('leaves pendingTree null for a no-op move (same position)', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      move(result, tree, NodeIdentity.toKey('column', 10))

      expect(result.current.pendingTree).toBeNull()
    })

    it('clears a stale overRectRef snapshot when entering the pending path', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      // Simulate a tier-1 (same-container) snapshot captured before the
      // cross-container transition.
      act(() => {
        result.current.collisionRefs.overRectRef.current = {
          id: 'element-5',
          nodeRef: { current: null },
        }
      })

      move(result, tree)

      expect(result.current.collisionRefs.overRectRef.current).toBeNull()
    })

    it('clears overRectRef even for a no-op move (same position)', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.collisionRefs.overRectRef.current = {
          id: 'element-5',
          nodeRef: { current: null },
        }
      })

      move(result, tree, NodeIdentity.toKey('column', 10))

      expect(result.current.collisionRefs.overRectRef.current).toBeNull()
    })
  })

  describe('getEffective', () => {
    it('returns canonical tree when no pending tree', () => {
      const { tree } = buildFixtureTree()
      const maps = buildMaps(tree)

      const { result } = renderHook(() => usePendingTree())
      const effective = result.current.getEffective(tree, maps)
      expect(effective.tree).toBe(tree)
      expect(effective.maps).toBe(maps)
    })

    it('returns pending tree when one is set', () => {
      const { tree } = buildFixtureTree()

      const canonicalTree: TreeApiResponse = {
        rootParent: { type: 'page', id: 99 },
        nodes: [],
      }
      const canonicalMaps = buildMaps(canonicalTree)

      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      const effective = result.current.getEffective(canonicalTree, canonicalMaps)
      expect(effective.tree).not.toBe(canonicalTree)
      expect(effective.maps).not.toBe(canonicalMaps)
    })
  })

  describe('getActivePlacement', () => {
    it('returns null when no pending preview is active', () => {
      const { result } = renderHook(() => usePendingTree())
      expect(result.current.getActivePlacement(NodeIdentity.toKey('element', 5))).toBeNull()
    })

    it('reports the active element at the head of the target (after = null)', () => {
      const { tree, element } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      const placement = result.current.getActivePlacement(
        NodeIdentity.toKey('element', element.self.id),
      )
      expect(placement).not.toBeNull()
      expect(placement?.parent).toEqual({ type: 'column', id: 20 })
      expect(placement?.after).toBeNull()
    })

    it('reports the sibling the active element sits after when appended', () => {
      // col 10 has [element 5]; col 20 has [element 6]. Move element 5 into col 20
      // after element 6 → col 20 = [6, 5]. The placement anchor is element 6.
      const existing = createSimpleElement({ id: 6, parent: { type: 'column', id: 20 } })
      const { tree } = buildTree({
        sectionId: 1000,
        rows: [
          {
            id: 100,
            columns: [
              {
                id: 10,
                children: [createSimpleElement({ id: 5, parent: { type: 'column', id: 10 } })],
              },
              { id: 20, children: [existing] },
            ],
          },
        ],
      })

      const { result } = renderHook(() => usePendingTree())

      move(result, tree, NodeIdentity.toKey('column', 20), 6)

      const placement = result.current.getActivePlacement(NodeIdentity.toKey('element', 5))
      expect(placement?.parent).toEqual({ type: 'column', id: 20 })
      expect(placement?.after).toEqual({ type: 'element', id: 6 })
    })

    it('returns null for an active key absent from the pending tree', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      move(result, tree)

      expect(result.current.getActivePlacement(NodeIdentity.toKey('element', 999))).toBeNull()
    })
  })

  describe('clear', () => {
    it('resets pendingTree and collision refs', () => {
      const { tree } = buildFixtureTree()
      const { result } = renderHook(() => usePendingTree())

      act(() => {
        result.current.setSourceSiblings(new Set(['element-1']))
      })
      move(result, tree)
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
