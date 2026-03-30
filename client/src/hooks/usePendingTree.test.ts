import { describe, it, expect } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { usePendingTree } from './usePendingTree';
import { buildMaps } from './useElementMaps';
import {
  createColumnNode,
  createRowNode,
  createSectionNode,
  createSimpleElement,
  resetIdCounter,
} from '@/testing/factories';
import type { ElementTreeResponse } from '@/types/elements';

beforeEach(() => {
  resetIdCounter();
});

/**
 * Build a tree with a numeric root key so applyReorder can resolve
 * parent IDs via Number(rootKey). Two columns under one row:
 * col1 has one element, col2 is empty.
 */
function buildTwoColumnTree(pageId = 1) {
  const element = createSimpleElement({ id: 5, parentId: 10 });
  const col1 = createColumnNode({ id: 10, parentId: 100, children: [element] });
  const col2 = createColumnNode({ id: 20, parentId: 100, children: [] });
  const row = createRowNode({ id: 100, parentId: 1000, children: [col1, col2] });
  const section = createSectionNode({ id: 1000, parentId: pageId, children: [row] });
  const tree: ElementTreeResponse = {
    [String(pageId)]: [section],
  };
  return { tree, element, col1, col2 };
}

describe('usePendingTree', () => {
  it('initially returns pendingTree as null', () => {
    const { result } = renderHook(() => usePendingTree());

    expect(result.current.pendingTree).toBeNull();
  });

  it('initially has hasPendingMoveRef set to false', () => {
    const { result } = renderHook(() => usePendingTree());

    expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(false);
  });

  describe('applyPendingMove', () => {
    it('sets pending tree and returns new tree/maps for a cross-container move', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        moveResult = result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20, // target parent: col2
          null, // insert at beginning
          tree,
        );
      });

      expect(moveResult!).not.toBeNull();
      expect(result.current.pendingTree).not.toBeNull();

      // The moved element should now be a child of col2 in the new tree
      const newMaps = moveResult!.maps;
      const col2Children = newMaps.childrenByParentId.get(20);
      expect(col2Children).toHaveLength(1);
      expect(col2Children![0].id).toBe(element.id);

      // col1 should now be empty
      const col1Children = newMaps.childrenByParentId.get(10);
      expect(col1Children).toHaveLength(0);
    });

    it('sets hasPendingMoveRef to true', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });

      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true);
    });

    it('updates pendingContainerItemsRef with target siblings', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });

      const pendingItems = result.current.collisionRefs.pendingContainerItemsRef.current;
      expect(pendingItems).not.toBeNull();
      expect(pendingItems!.size).toBe(1);
      expect(pendingItems!.has(`element-${element.id}`)).toBe(true);
    });

    it('returns null for a no-op move (same position)', () => {
      const element = createSimpleElement({ id: 5, parentId: 10 });
      const col = createColumnNode({ id: 10, parentId: 100, children: [element] });
      const row = createRowNode({ id: 100, parentId: 1000, children: [col] });
      const section = createSectionNode({ id: 1000, parentId: 1, children: [row] });
      const tree: ElementTreeResponse = { '1': [section] };

      const { result } = renderHook(() => usePendingTree());

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        // Move element to same parent, same position (first = afterElementId null)
        moveResult = result.current.applyPendingMove(
          { type: 'element', id: 5 },
          10,
          null,
          tree,
        );
      });

      expect(moveResult!).toBeNull();
      expect(result.current.pendingTree).toBeNull();
    });
  });

  describe('getEffective', () => {
    it('returns canonical tree when no pending tree', () => {
      const { tree } = buildTwoColumnTree();
      const maps = buildMaps(tree);

      const { result } = renderHook(() => usePendingTree());

      const effective = result.current.getEffective(tree, maps);
      expect(effective.tree).toBe(tree);
      expect(effective.maps).toBe(maps);
    });

    it('returns pending tree when one is set', () => {
      const { tree, element } = buildTwoColumnTree();

      // Separate canonical tree to compare against
      const canonicalTree: ElementTreeResponse = { '99': [] };
      const canonicalMaps = buildMaps(canonicalTree);

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });

      const effective = result.current.getEffective(canonicalTree, canonicalMaps);
      expect(effective.tree).not.toBe(canonicalTree);
      expect(effective.maps).not.toBe(canonicalMaps);
    });
  });

  describe('clear', () => {
    it('resets pendingTree to null', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.clear();
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('resets hasPendingMoveRef to false', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });
      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true);

      act(() => {
        result.current.clear();
      });

      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(false);
    });

    it('resets collisionRefs to null', () => {
      const { tree, element } = buildTwoColumnTree();

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.setSourceSiblings(new Set(['element-1']));
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });

      act(() => {
        result.current.clear();
      });

      expect(result.current.collisionRefs.pendingContainerItemsRef.current).toBeNull();
      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBeNull();
      expect(result.current.collisionRefs.overRectRef.current).toBeNull();
    });

    it('makes getEffective return canonical tree again', () => {
      const { tree, element } = buildTwoColumnTree();
      const maps = buildMaps(tree);

      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'element', id: element.id },
          20,
          null,
          tree,
        );
      });

      act(() => {
        result.current.clear();
      });

      const effective = result.current.getEffective(tree, maps);
      expect(effective.tree).toBe(tree);
      expect(effective.maps).toBe(maps);
    });
  });

  describe('setSourceSiblings', () => {
    it('stores siblings in sourceContainerItemsRef', () => {
      const { result } = renderHook(() => usePendingTree());

      const siblings = new Set<string | number>(['element-1', 'element-2']);
      act(() => {
        result.current.setSourceSiblings(siblings);
      });

      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBe(siblings);
    });
  });
});
