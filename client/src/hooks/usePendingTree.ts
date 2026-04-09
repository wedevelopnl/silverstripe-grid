import { useCallback, useMemo, useRef, useState } from 'react';
import type { MutableRefObject } from 'react';
import type { ParsedDraggableId } from '@/types/dnd';
import { buildDraggableId } from '@/types/dnd';
import type { ElementTreeResponse } from '@/types/elements';
import { buildMaps } from '@/hooks/useElementMaps';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { applyReorder } from '@/utils/applyReorder';
import type { OverRectSnapshot } from '@/utils/collisionDetection';

export interface CollisionRefs {
  readonly hasPendingMoveRef: MutableRefObject<boolean>;
  readonly pendingContainerItemsRef: MutableRefObject<ReadonlySet<string | number> | null>;
  readonly sourceContainerItemsRef: MutableRefObject<ReadonlySet<string | number> | null>;
  readonly overRectRef: MutableRefObject<OverRectSnapshot | null>;
}

export interface UsePendingTreeReturn {
  /** React state for rendering — the tree with pending move applied, or null. */
  pendingTree: ElementTreeResponse | null;

  /** Refs exposed for collision detection (read-only from its perspective). */
  collisionRefs: CollisionRefs;

  /** Apply a cross-container move. Returns new tree/maps, or null if no-op. */
  applyPendingMove(
    activeParsed: ParsedDraggableId,
    targetParentId: number,
    afterElementId: number | null,
    effectiveTree: ElementTreeResponse,
  ): { tree: ElementTreeResponse; maps: ElementMaps } | null;

  /** Set source container siblings (called on drag start). */
  setSourceSiblings(siblings: ReadonlySet<string | number>): void;

  /** Get effective tree/maps (pending or canonical). */
  getEffective(
    canonicalTree: ElementTreeResponse,
    canonicalMaps: ElementMaps,
  ): { tree: ElementTreeResponse; maps: ElementMaps };

  /** Reset all pending state. */
  clear(): void;
}

/**
 * Encapsulates pending tree state and collision detection refs for
 * cross-container drag-and-drop moves.
 */
export function usePendingTree(): UsePendingTreeReturn {
  const [pendingTree, setPendingTree] = useState<ElementTreeResponse | null>(null);
  const pendingTreeRef = useRef<ElementTreeResponse | null>(null);
  const pendingMapsRef = useRef<ElementMaps | null>(null);
  const hasPendingMoveRef = useRef(false);
  const overRectRef = useRef<OverRectSnapshot | null>(null);
  const pendingContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null);
  const sourceContainerItemsRef = useRef<ReadonlySet<string | number> | null>(null);

  const collisionRefs = useMemo<CollisionRefs>(
    () => ({
      hasPendingMoveRef,
      pendingContainerItemsRef,
      sourceContainerItemsRef,
      overRectRef,
    }),
    [],
  );

  const applyPendingMove = useCallback(
    (
      activeParsed: ParsedDraggableId,
      targetParentId: number,
      afterElementId: number | null,
      effectiveTree: ElementTreeResponse,
    ): { tree: ElementTreeResponse; maps: ElementMaps } | null => {
      const newTree = applyReorder(effectiveTree, activeParsed.id, targetParentId, afterElementId);
      if (newTree === effectiveTree) return null;

      const newMaps = buildMaps(newTree);
      pendingTreeRef.current = newTree;
      pendingMapsRef.current = newMaps;
      hasPendingMoveRef.current = true;

      const targetSiblings = newMaps.childrenByParentId.get(targetParentId) ?? [];
      pendingContainerItemsRef.current = new Set(
        targetSiblings.map((n) => buildDraggableId(activeParsed.type, n.id)),
      );

      setPendingTree(newTree);
      return { tree: newTree, maps: newMaps };
    },
    [],
  );

  const setSourceSiblings = useCallback((siblings: ReadonlySet<string | number>) => {
    sourceContainerItemsRef.current = siblings;
  }, []);

  const getEffective = useCallback(
    (
      canonicalTree: ElementTreeResponse,
      canonicalMaps: ElementMaps,
    ): { tree: ElementTreeResponse; maps: ElementMaps } => {
      if (pendingTreeRef.current !== null && pendingMapsRef.current !== null) {
        return { tree: pendingTreeRef.current, maps: pendingMapsRef.current };
      }
      return { tree: canonicalTree, maps: canonicalMaps };
    },
    [],
  );

  const clear = useCallback(() => {
    pendingTreeRef.current = null;
    pendingMapsRef.current = null;
    hasPendingMoveRef.current = false;
    overRectRef.current = null;
    pendingContainerItemsRef.current = null;
    sourceContainerItemsRef.current = null;
    setPendingTree(null);
  }, []);

  return { pendingTree, collisionRefs, applyPendingMove, setSourceSiblings, getEffective, clear };
}
