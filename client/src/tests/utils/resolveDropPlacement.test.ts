import { resolveDropPlacement } from '@/utils/resolveDropPlacement';
import type { DropContext } from '@/utils/resolveDropPlacement';
import { buildMaps } from '@/hooks/useElementMaps';
import type { ElementTreeResponse } from '@/types/elements';
import { makeElement, makeColumn, makeRow, makeSection } from '../helpers/elementFactories';

const ZERO_RECT = { left: 0, top: 0, width: 100, height: 50 };

// --- Test trees ---

// Tree A: elements in columns
//   Section 1 → Row 10 → Column 20 [Element 30, Element 31], Column 21 [Element 32]
const elementTree: ElementTreeResponse = {
  '42': [
    makeSection(1, [
      makeRow(10, [
        makeColumn(20, [makeElement(30, 20), makeElement(31, 20)], 10),
        makeColumn(21, [makeElement(32, 21)], 10),
      ], 1),
    ], 42),
  ],
};

// Tree B: rows in sections
//   Section 2 → Row 11, Row 12
//   Section 3 → Row 13, Row 14
const rowTree: ElementTreeResponse = {
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

// Tree C: columns in rows (for X-axis direction tests)
//   Section 1 → Row 10 [Column 20, Column 21], Row 11 [Column 22, Column 23]
const columnTree: ElementTreeResponse = {
  '42': [
    makeSection(1, [
      makeRow(10, [
        makeColumn(20, [makeElement(30, 20)], 10),
        makeColumn(21, [makeElement(31, 21)], 10),
      ], 1),
      makeRow(11, [
        makeColumn(22, [makeElement(32, 22)], 11),
        makeColumn(23, [makeElement(33, 23)], 11),
      ], 1),
    ], 42),
  ],
};

// Tree D: element tree with empty column
const emptyColumnTree: ElementTreeResponse = {
  '42': [
    makeSection(1, [
      makeRow(10, [
        makeColumn(20, [makeElement(30, 20)], 10),
        makeColumn(21, [], 10),
      ], 1),
    ], 42),
  ],
};

// --- Helper to build DropContext ---

function makeDropContext(overrides: Partial<DropContext> & Pick<DropContext, 'activeParsed' | 'overParsed' | 'maps' | 'sourceParentId' | 'sourceIndex'>): DropContext {
  return {
    pointer: null,
    overRect: ZERO_RECT,
    ...overrides,
  };
}

describe('resolveDropPlacement', () => {
  describe('same-container reordering (no direction applied)', () => {
    it('swaps sibling to later position', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 31 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      expect(result).toEqual({ elementID: 30, targetParentId: 20, afterElementID: 31 });
    });

    it('swaps sibling to earlier position', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 31 },
        overParsed: { type: 'element', id: 30 },
        maps,
        sourceParentId: 20,
        sourceIndex: 1,
      }));

      expect(result).toEqual({ elementID: 31, targetParentId: 20, afterElementID: null });
    });

    it('returns null for same position (no-op)', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 30 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      // over === active means the calling code wouldn't invoke this, but
      // if it does, resolveReorderParams catches it as a no-op
      expect(result).toBeNull();
    });
  });

  describe('cross-container placement (direction applied)', () => {
    it('places before sibling when pointer is above center (Y-axis, rows)', () => {
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        overParsed: { type: 'row', id: 13 },
        pointer: { x: 50, y: 200 },
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
        overRect: { left: 0, top: 250, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: null });
    });

    it('places after sibling when pointer is below center (Y-axis, rows)', () => {
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        overParsed: { type: 'row', id: 13 },
        pointer: { x: 50, y: 300 },
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
        overRect: { left: 0, top: 250, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: 13 });
    });

    it('places before sibling when pointer is left of center (X-axis, columns)', () => {
      const maps = buildMaps(columnTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'column', id: 20 },
        overParsed: { type: 'column', id: 22 },
        pointer: { x: 200, y: 25 },
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: { left: 200, top: 0, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 20, targetParentId: 11, afterElementID: null });
    });

    it('places after sibling when pointer is right of center (X-axis, columns)', () => {
      const maps = buildMaps(columnTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'column', id: 20 },
        overParsed: { type: 'column', id: 22 },
        pointer: { x: 300, y: 25 },
        maps,
        sourceParentId: 10,
        sourceIndex: 0,
        overRect: { left: 200, top: 0, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 20, targetParentId: 11, afterElementID: 22 });
    });

    it('places before element when pointer is above center (Y-axis, elements)', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 32 },
        pointer: { x: 50, y: 200 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
        overRect: { left: 0, top: 250, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 30, targetParentId: 21, afterElementID: null });
    });

    it('uses overRect index when pointer is null (no direction shift)', () => {
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        overParsed: { type: 'row', id: 13 },
        pointer: null,
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
      }));

      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: null });
    });

    it('places after last sibling in cross-container move', () => {
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        overParsed: { type: 'row', id: 14 },
        pointer: { x: 50, y: 350 },
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
        overRect: { left: 0, top: 300, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: 14 });
    });
  });

  describe('drop into container', () => {
    it('appends to empty container', () => {
      const maps = buildMaps(emptyColumnTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'column', id: 21 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      expect(result).toEqual({ elementID: 30, targetParentId: 21, afterElementID: null });
    });

    it('appends to non-empty container', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'column', id: 21 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      expect(result).toEqual({ elementID: 30, targetParentId: 21, afterElementID: 32 });
    });
  });

  describe('edge cases', () => {
    it('returns null when over node is not in maps', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 999 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      expect(result).toBeNull();
    });

    it('returns null when over is a non-container with different type', () => {
      const maps = buildMaps(elementTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'row', id: 999 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      expect(result).toBeNull();
    });

    it('handles cross-container move with over element not in filtered list', () => {
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        overParsed: { type: 'row', id: 14 },
        pointer: { x: 50, y: 350 },
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
        overRect: { left: 0, top: 300, width: 100, height: 50 },
      }));

      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: 14 });
    });
  });

  describe('index-based mutant killing', () => {
    // Tree with 3 elements in a single column to test overOriginalIdx at index 1
    //   Section 1 → Row 10 → Column 20 [Element 30, Element 31, Element 32]
    const threeElementTree: ElementTreeResponse = {
      '42': [
        makeSection(1, [
          makeRow(10, [
            makeColumn(20, [makeElement(30, 20), makeElement(31, 20), makeElement(32, 20)], 10),
          ], 1),
        ], 42),
      ],
    };

    it('same-container: correctly places when over element is at index 1 (kills -1 → +1 mutant on overOriginalIdx)', () => {
      const maps = buildMaps(threeElementTree);
      // Drag element 30 (index 0) over element 31 (index 1).
      // If -1 mutated to +1, overOriginalIdx === 1 would be treated as "not found"
      // and insertIndex would become filtered.length instead of 1.
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 31 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      // Element 30 should be placed at index 1 (after element 31)
      expect(result).toEqual({ elementID: 30, targetParentId: 20, afterElementID: 31 });
    });

    // Tree for cross-container test: target has 3 elements so index 1 is meaningful
    //   Section 1 → Row 10 → Column 20 [Element 30], Column 21 [Element 40, Element 41, Element 42]
    const crossContainerThreeElementTree: ElementTreeResponse = {
      '42': [
        makeSection(1, [
          makeRow(10, [
            makeColumn(20, [makeElement(30, 20)], 10),
            makeColumn(21, [makeElement(40, 21), makeElement(41, 21), makeElement(42, 21)], 10),
          ], 1),
        ], 42),
      ],
    };

    it('cross-container: correctly places when over element is at index 1 in filtered list (kills -1 → +1 mutant on overIdx)', () => {
      const maps = buildMaps(crossContainerThreeElementTree);
      // Drag element 30 from column 20 over element 41 (index 1 in column 21).
      // Pointer above center → direction = "before" → insertIndex stays at 1.
      // If -1 mutated to +1, overIdx === 1 would be treated as "not found"
      // and insertIndex would become filtered.length (3) instead of 1.
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'element', id: 41 },
        pointer: { x: 50, y: 200 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
        overRect: { left: 0, top: 250, width: 100, height: 50 },
      }));

      // Should place before element 41 (after element 40)
      expect(result).toEqual({ elementID: 30, targetParentId: 21, afterElementID: 40 });
    });
  });

  describe('drop-into-own-parent container (filter mutant killing)', () => {
    // Tree: Column 20 has [Element 30, Element 31]
    // Dropping element 30 onto column 20 (its own parent) should filter element 30
    // from the children list before computing insertion index.
    it('filters active element from container children when dropping into own parent', () => {
      const maps = buildMaps(elementTree);
      // Element 30 (child of column 20) dropped onto column 20.
      // Without the filter on line 104, element-30 would appear twice in the
      // final list (once from children, once from splice), producing a wrong
      // afterElementID.
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'element', id: 30 },
        overParsed: { type: 'column', id: 20 },
        maps,
        sourceParentId: 20,
        sourceIndex: 0,
      }));

      // After filtering active (element 30) from children [30, 31],
      // filtered = [element-31]. insertIndex = filtered.length = 1.
      // Splice inserts element-30 at index 1 → afterElementID = 31.
      expect(result).toEqual({ elementID: 30, targetParentId: 20, afterElementID: 31 });
    });
  });
});
