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
  createTreeApiResponse,
  createParsedDraggableId,
} from '@/testing/factories';
import { buildNodeKey } from '@/types/identity';

const DEFAULT_RECT = { left: 0, top: 0, width: 200, height: 100 };

/**
 * Section 100 > Row 10 > Col 21, Col 22, Col 23 (no leaves).
 */
function buildThreeColumnRow() {
  const row = createRowNode({
    id: 10,
    parent: { type: 'section', id: 100 },
    children: [
      createColumnNode({ id: 21, parent: { type: 'row', id: 10 }, childCount: 0 }),
      createColumnNode({ id: 22, parent: { type: 'row', id: 10 }, childCount: 0 }),
      createColumnNode({ id: 23, parent: { type: 'row', id: 10 }, childCount: 0 }),
    ],
  });
  const section = createSectionNode({
    id: 100,
    parent: { type: 'page', id: 1 },
    children: [row],
  });
  return buildMaps(createTreeApiResponse({ pageId: 1, sections: [section] }));
}

/**
 * Section 100 > Row 10 (Col 21, 22), Row 11 (Col 31, 32).
 */
function buildTwoRowTree() {
  const row1 = createRowNode({
    id: 10,
    parent: { type: 'section', id: 100 },
    children: [
      createColumnNode({ id: 21, parent: { type: 'row', id: 10 }, childCount: 0 }),
      createColumnNode({ id: 22, parent: { type: 'row', id: 10 }, childCount: 0 }),
    ],
  });
  const row2 = createRowNode({
    id: 11,
    parent: { type: 'section', id: 100 },
    children: [
      createColumnNode({ id: 31, parent: { type: 'row', id: 11 }, childCount: 0 }),
      createColumnNode({ id: 32, parent: { type: 'row', id: 11 }, childCount: 0 }),
    ],
  });
  const section = createSectionNode({
    id: 100,
    parent: { type: 'page', id: 1 },
    children: [row1, row2],
  });
  return buildMaps(createTreeApiResponse({ pageId: 1, sections: [section] }));
}

describe('resolveDropPlacement', () => {
  beforeEach(() => {
    resetIdCounter();
  });

  describe('same-type same-container', () => {
    it('returns correct params when reordering within same parent', () => {
      const maps = buildThreeColumnRow();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 23),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      const result = resolveDropPlacement(ctx);

      expect(result).toEqual({
        element: { type: 'column', id: 21 },
        parent: { type: 'row', id: 10 },
        after: { type: 'column', id: 23 },
      });
    });

    it('returns null when item does not move', () => {
      const maps = buildThreeColumnRow();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 21),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      expect(resolveDropPlacement(ctx)).toBeNull();
    });
  });

  describe('same-type cross-container', () => {
    it('applies pointer-based direction (before) for insertion position', () => {
      const maps = buildTwoRowTree();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 31),
        pointer: { x: 10, y: 50 },
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      expect(resolveDropPlacement(ctx)).toEqual({
        element: { type: 'column', id: 21 },
        parent: { type: 'row', id: 11 },
        after: null,
      });
    });

    it('applies pointer-based direction (after) for insertion position', () => {
      const maps = buildTwoRowTree();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 31),
        pointer: { x: 150, y: 50 },
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      expect(resolveDropPlacement(ctx)).toEqual({
        element: { type: 'column', id: 21 },
        parent: { type: 'row', id: 11 },
        after: { type: 'column', id: 31 },
      });
    });

    it('falls back to append position when pointer is null', () => {
      const maps = buildTwoRowTree();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 31),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      expect(resolveDropPlacement(ctx)).toEqual({
        element: { type: 'column', id: 21 },
        parent: { type: 'row', id: 11 },
        after: null,
      });
    });

    it('returns correct targetParent from the over element parent', () => {
      const maps = buildTwoRowTree();

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 21),
        overParsed: createParsedDraggableId('column', 32),
        pointer: { x: 150, y: 50 },
        maps,
        sourceParentKey: buildNodeKey('row', 10),
        sourceIndex: 0,
        overRect: { left: 0, top: 0, width: 200, height: 100 },
      };

      const result = resolveDropPlacement(ctx);
      expect(result).not.toBeNull();
      expect(result?.parent).toEqual({ type: 'row', id: 11 });
    });
  });

  describe('cross-type (drop into container)', () => {
    it('drops element at end of container children', () => {
      const row = createRowNode({
        id: 10,
        parent: { type: 'section', id: 100 },
        children: [
          createColumnNode({
            id: 50,
            parent: { type: 'row', id: 10 },
            children: [
              createSimpleElement({ id: 61, parent: { type: 'column', id: 50 } }),
              createSimpleElement({ id: 62, parent: { type: 'column', id: 50 } }),
            ],
          }),
          createColumnNode({
            id: 51,
            parent: { type: 'row', id: 10 },
            children: [createSimpleElement({ id: 71, parent: { type: 'column', id: 51 } })],
          }),
        ],
      });
      const section = createSectionNode({
        id: 100,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const maps = buildMaps(createTreeApiResponse({ pageId: 1, sections: [section] }));

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('element', 61),
        overParsed: createParsedDraggableId('column', 51),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('column', 50),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      expect(resolveDropPlacement(ctx)).toEqual({
        element: { type: 'element', id: 61 },
        parent: { type: 'column', id: 51 },
        after: { type: 'element', id: 71 },
      });
    });

    it('returns null when the over element is not a container node', () => {
      const col = createColumnNode({
        id: 50,
        parent: { type: 'row', id: 10 },
        children: [
          createSimpleElement({ id: 61, parent: { type: 'column', id: 50 } }),
          createSimpleElement({ id: 62, parent: { type: 'column', id: 50 } }),
        ],
      });
      const row = createRowNode({
        id: 10,
        parent: { type: 'section', id: 100 },
        children: [col],
      });
      const section = createSectionNode({
        id: 100,
        parent: { type: 'page', id: 1 },
        children: [row],
      });
      const maps = buildMaps(createTreeApiResponse({ pageId: 1, sections: [section] }));

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('element', 61),
        overParsed: createParsedDraggableId('column', 62),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('column', 50),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      expect(resolveDropPlacement(ctx)).toBeNull();
    });
  });

  describe('edge cases', () => {
    it('returns null when over element not found in nodeMap', () => {
      const section = createSectionNode({ id: 100, parent: { type: 'page', id: 1 } });
      const maps = buildMaps(createTreeApiResponse({ pageId: 1, sections: [section] }));

      const ctx: DropContext = {
        activeParsed: createParsedDraggableId('column', 999),
        overParsed: createParsedDraggableId('column', 888),
        pointer: null,
        maps,
        sourceParentKey: buildNodeKey('row', 1),
        sourceIndex: 0,
        overRect: DEFAULT_RECT,
      };

      expect(resolveDropPlacement(ctx)).toBeNull();
    });
  });
});
