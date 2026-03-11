import { renderHook, act } from '@testing-library/react';
import { usePendingTree } from '@/hooks/usePendingTree';
import { buildMaps } from '@/hooks/useElementMaps';
import type { ElementTreeResponse } from '@/types/elements';
import { makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

const testTree: ElementTreeResponse = {
  '42': [
    makeSection(2, [
      makeRow(11, [makeColumn(50, [], 11)], 2),
      makeRow(12, [makeColumn(51, [], 12)], 2),
    ], 42),
    makeSection(3, [
      makeRow(13, [makeColumn(52, [], 13)], 3),
      makeRow(14, [makeColumn(53, [], 14)], 3),
    ], 42),
  ],
};

describe('usePendingTree', () => {
  it('initializes with null pendingTree', () => {
    const { result } = renderHook(() => usePendingTree());
    expect(result.current.pendingTree).toBeNull();
  });

  it('provides stable collisionRefs across renders', () => {
    const { result, rerender } = renderHook(() => usePendingTree());
    const firstRefs = result.current.collisionRefs;
    rerender();
    expect(result.current.collisionRefs).toBe(firstRefs);
  });

  describe('applyPendingMove', () => {
    it('sets pendingTree for a cross-container move', () => {
      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3,    // target: section 3
          14,   // after row-14
          testTree,
        );
      });

      expect(result.current.pendingTree).not.toBeNull();
      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true);
      expect(result.current.collisionRefs.pendingContainerItemsRef.current).not.toBeNull();
    });

    it('returns new tree and maps on success', () => {
      const { result } = renderHook(() => usePendingTree());

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        moveResult = result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3,
          14,
          testTree,
        );
      });

      expect(moveResult!).not.toBeNull();
      expect(moveResult!.tree).not.toBe(testTree);
      expect(moveResult!.maps).toBeDefined();
    });

    it('returns null when move is a no-op', () => {
      const { result } = renderHook(() => usePendingTree());

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        // Move row-11 after nothing in section 2 (already first in section 2)
        moveResult = result.current.applyPendingMove(
          { type: 'row', id: 11 },
          2,    // same parent
          null, // first position (same as current)
          testTree,
        );
      });

      expect(moveResult!).toBeNull();
      expect(result.current.pendingTree).toBeNull();
    });
  });

  describe('getEffective', () => {
    it('returns canonical when no pending tree', () => {
      const { result } = renderHook(() => usePendingTree());
      const maps = buildMaps(testTree);

      const effective = result.current.getEffective(testTree, maps);
      expect(effective.tree).toBe(testTree);
      expect(effective.maps).toBe(maps);
    });

    it('returns pending tree when available', () => {
      const { result } = renderHook(() => usePendingTree());
      const maps = buildMaps(testTree);

      act(() => {
        result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3,
          14,
          testTree,
        );
      });

      const effective = result.current.getEffective(testTree, maps);
      expect(effective.tree).not.toBe(testTree);
      expect(effective.tree).toBe(result.current.pendingTree);
    });
  });

  describe('clear', () => {
    it('resets pendingTree to null', () => {
      const { result } = renderHook(() => usePendingTree());

      act(() => {
        result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3, 14, testTree,
        );
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.clear();
      });

      expect(result.current.pendingTree).toBeNull();
      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(false);
      expect(result.current.collisionRefs.pendingContainerItemsRef.current).toBeNull();
      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBeNull();
      expect(result.current.collisionRefs.overRectRef.current).toBeNull();
    });
  });

  describe('setSourceSiblings', () => {
    it('updates sourceContainerItemsRef', () => {
      const { result } = renderHook(() => usePendingTree());
      const siblings = new Set(['row-12']);

      act(() => {
        result.current.setSourceSiblings(siblings);
      });

      expect(result.current.collisionRefs.sourceContainerItemsRef.current).toBe(siblings);
    });
  });
});
