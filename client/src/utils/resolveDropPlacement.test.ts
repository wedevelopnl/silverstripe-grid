// @ts-nocheck — TODO(phase-5): rewrite for NodeRef/NodeKey identity model; tracked in plan polished-floating-bubble.md
import { describe, it, expect, beforeEach } from 'vitest';
import { resolveDropPlacement } from '@/utils/resolveDropPlacement';
import type { DropContext } from '@/utils/resolveDropPlacement';
import { buildMaps } from '@/hooks/useElementMaps';
import {
  resetIdCounter,
  createSectionNode,
  createRowNode,
  createColumnNode,
  createSimpleElement,
  createTree,
} from '@/testing/factories';

const DEFAULT_RECT = { left: 0, top: 0, width: 200, height: 100 };

/**
 * Builds a tree with explicit IDs to avoid argument-evaluation-order surprises.
 *
 * Structure:
 *   Section 100 > Row 10 > Col 21, Col 22, Col 23
 *   (columns have no leaf children to keep IDs predictable)
 */
function buildThreeColumnRow() {
  const row = createRowNode({
    id: 10,
    parentId: 100,
    children: [
      createColumnNode({ id: 21, parentId: 10, childCount: 0 }),
      createColumnNode({ id: 22, parentId: 10, childCount: 0 }),
      createColumnNode({ id: 23, parentId: 10, childCount: 0 }),
    ],
  });
  const section = createSectionNode({ id: 100, parentId: 1, children: [row] });
  return buildMaps(createTree([section]));
}

/**
 * Builds a tree with two rows, each containing two columns.
 *
 * Structure:
 *   Section 100
 *     Row 10: Col 21, Col 22
 *     Row 11: Col 31, Col 32
 */
function buildTwoRowTree() {
  const row1 = createRowNode({
    id: 10,
    parentId: 100,
    children: [
      createColumnNode({ id: 21, parentId: 10, childCount: 0 }),
      createColumnNode({ id: 22, parentId: 10, childCount: 0 }),
    ],
  });
  const row2 = createRowNode({
    id: 11,
    parentId: 100,
    children: [
      createColumnNode({ id: 31, parentId: 11, childCount: 0 }),
      createColumnNode({ id: 32, parentId: 11, childCount: 0 }),
    ],
  });
  const section = createSectionNode({ id: 100, parentId: 1, children: [row1, row2] });
  return buildMaps(createTree([section]));
}

describe('resolveDropPlacement', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  describe('same-type same-container', () => {
    it('returns correct params when reordering within same parent', () => {
      const maps = buildThreeColumnRow();

      // Drag col 21 over col 23 (both in row 10)
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 23 },
        pointer: null,
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      // Same-container: col 21 placed at overOriginalIdx=2.
      // filtered=[col-22, col-23], splice at 2 -> [col-22, col-23, col-21]
      // afterElementID = 23 (item before index 2)
      expect(result).toEqual({
        elementID: 21,
        targetParentId: 10,
        afterElementID: 23,
      });
    });

    it('returns null when item does not move', () => {
      const maps = buildThreeColumnRow();

      // Drag col 21 over itself at index 0 — no-op
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 21 },
        pointer: null,
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      expect(result).toBeNull();
    });
  });

  describe('same-type cross-container', () => {
    it('applies pointer-based direction (before) for insertion position', () => {
      const maps = buildTwoRowTree();

      // Drag col 21 (from row 10) over col 31 (in row 11), pointer left of center -> before
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 31 },
        pointer: { x: 10, y: 50 },
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      const result = resolveDropPlacement(ctx);

      // Before col 31 -> inserted at index 0, afterElementID = null
      expect(result).toEqual({
        elementID: 21,
        targetParentId: 11,
        afterElementID: null,
      });
    });

    it('applies pointer-based direction (after) for insertion position', () => {
      const maps = buildTwoRowTree();

      // Drag col 21 (from row 10) over col 31 (in row 11), pointer right of center -> after
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 31 },
        pointer: { x: 150, y: 50 },
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      const result = resolveDropPlacement(ctx);

      // After col 31 -> inserted at index 1, afterElementID = 31
      expect(result).toEqual({
        elementID: 21,
        targetParentId: 11,
        afterElementID: 31,
      });
    });

    it('falls back to append position when pointer is null', () => {
      const maps = buildTwoRowTree();

      // Drag col 21 (from row 10) over col 31 (in row 11), no pointer -> overIdx used directly
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 31 },
        pointer: null,
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      // No pointer: insertIndex = overIdx (0), afterElementID = null
      expect(result).toEqual({
        elementID: 21,
        targetParentId: 11,
        afterElementID: null,
      });
    });

    it('returns correct targetParentId from over element parent', () => {
      const maps = buildTwoRowTree();

      // Drag col 21 over col 32 (second col in row 11) — targetParentId should be 11
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 32 },
        pointer: { x: 150, y: 50 },
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      const result = resolveDropPlacement(ctx);

      expect(result).not.toBeNull();
      expect(result!.targetParentId).toBe(11);
    });
  });

  describe('cross-type (drop into container)', () => {
    it('drops element at end of container children', () => {
      // Col 50 has elem 61, elem 62. Col 51 has elem 71.
      const row = createRowNode({
        id: 10,
        parentId: 100,
        children: [
          createColumnNode({
            id: 50,
            parentId: 10,
            children: [
              createSimpleElement({ id: 61, parentId: 50 }),
              createSimpleElement({ id: 62, parentId: 50 }),
            ],
          }),
          createColumnNode({
            id: 51,
            parentId: 10,
            children: [createSimpleElement({ id: 71, parentId: 51 })],
          }),
        ],
      });
      const section = createSectionNode({ id: 100, parentId: 1, children: [row] });
      const maps = buildMaps(createTree([section]));

      // Drag element 61 (type 'element') over column 51 (type 'column') — cross-type drop
      const ctx: DropContext = {
        activeParsed: { type: 'element', id: 61 },
        overParsed: { type: 'column', id: 51 },
        pointer: null,
        maps,
        sourceParentId: 50,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      // Dropped at end of col 51's children: [elem-71, elem-61]
      expect(result).toEqual({
        elementID: 61,
        targetParentId: 51,
        afterElementID: 71,
      });
    });

    it('returns null if over element is not a container node', () => {
      const col = createColumnNode({
        id: 50,
        parentId: 10,
        children: [
          createSimpleElement({ id: 61, parentId: 50 }),
          createSimpleElement({ id: 62, parentId: 50 }),
        ],
      });
      const row = createRowNode({ id: 10, parentId: 100, children: [col] });
      const section = createSectionNode({ id: 100, parentId: 1, children: [row] });
      const maps = buildMaps(createTree([section]));

      // overParsed type differs from activeParsed, but elem 62 is not a container
      const ctx: DropContext = {
        activeParsed: { type: 'element', id: 61 },
        overParsed: { type: 'column', id: 62 },
        pointer: null,
        maps,
        sourceParentId: 50,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      expect(result).toBeNull();
    });
  });

  describe('edge cases', () => {
    it('returns null when over element not found in nodeMap', () => {
      const section = createSectionNode({ id: 100 });
      const maps = buildMaps(createTree([section]));

      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 999 },
        overParsed: { type: 'column', id: 888 },
        pointer: null,
        maps,
        sourceParentId: 1,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      expect(result).toBeNull();
    });

    it('resolves afterElementId correctly from container items', () => {
      const maps = buildThreeColumnRow();

      // Drag col 21 over col 23 (same parent row 10, index 2)
      const ctx: DropContext = {
        activeParsed: { type: 'column', id: 21 },
        overParsed: { type: 'column', id: 23 },
        pointer: null,
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      // Same container: overOriginalIdx=2, filtered=[col-22,col-23].
      // splice(2, 0, col-21) -> [col-22,col-23,col-21]. indexOf(col-21)=2.
      // resolveAfterElementId walks back from 1: col-23 -> afterElementID=23
      expect(result).toEqual({
        elementID: 21,
        targetParentId: 10,
        afterElementID: 23,
      });
    });
  });
});
