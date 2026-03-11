# useDragAndDrop Hook Decomposition — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Decompose the 440-line `useDragAndDrop` monolith into focused units with clear testability seams.

**Architecture:** Extract insertion logic into a pure function (`resolveDropPlacement`), encapsulate ref management in a dedicated hook (`usePendingTree`), and reduce `useDragAndDrop` to a thin orchestrator. Group the public API by concern (`dndContextProps` for DndContext, separate `dragState`/`pendingTree` for rendering).

**Tech Stack:** React 18, TypeScript 5.9, dnd-kit, Vitest, React Testing Library

**Design doc:** `docs/plans/2026-03-11-dnd-hook-decomposition-design.md`

---

### Task 1: Extract `resolveDropPlacement` pure function

**Files:**
- Create: `client/src/utils/resolveDropPlacement.ts`
- Create: `client/src/tests/utils/resolveDropPlacement.test.ts`

**Step 1: Write the failing tests**

Create `client/src/tests/utils/resolveDropPlacement.test.ts`:

```typescript
import { resolveDropPlacement } from '@/utils/resolveDropPlacement';
import type { DropContext } from '@/utils/resolveDropPlacement';
import { buildMaps } from '@/hooks/useElementMaps';
import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
  ElementTreeResponse,
} from '@/types/elements';

// --- Factories (reused from existing test, no React needed) ---

function makeElement(id: number, parentId: number): SimpleElementNode {
  return {
    id,
    parentId,
    title: `Element ${id}`,
    blockSchema: { typeName: 'Element', label: 'Element', icon: 'font-icon-block-content', type: 'Element', title: '', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
  };
}

function makeColumn(id: number, children: SimpleElementNode[], parentId: number): ColumnNode {
  return {
    id,
    parentId,
    title: `Column ${id}`,
    blockSchema: { typeName: 'Column', label: 'Column', icon: 'font-icon-block-content', type: 'Column', title: '', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'column',
    allowedTypes: null,
    children,
    gridSettings: { md: { width: 6, offset: 0, visible: true } },
  };
}

function makeRow(id: number, children: ColumnNode[], parentId: number): RowNode {
  return {
    id,
    parentId,
    title: `Row ${id}`,
    blockSchema: { typeName: 'Row', label: 'Row', icon: 'font-icon-block-content', type: 'Row', title: '', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'row',
    allowedTypes: null,
    children,
  };
}

function makeSection(id: number, children: RowNode[], parentId: number): SectionNode {
  return {
    id,
    parentId,
    title: `Section ${id}`,
    blockSchema: { typeName: 'Section', label: 'Section', icon: 'font-icon-block-content', type: 'Section', title: '', summary: '' },
    obsoleteClassName: null,
    version: 1,
    canDelete: true,
    canPublish: true,
    canUnpublish: false,
    canCreate: true,
    editLink: null,
    statusFlags: {},
    containerType: 'section',
    allowedTypes: null,
    children,
  };
}

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
      // Element over a non-container node that parses as 'row' type
      // but ID 999 doesn't exist
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
      // This happens during pending tree moves when over element
      // is in a different container than where the pending tree placed it.
      // The over element won't appear in the filtered siblings → append to end.
      const maps = buildMaps(rowTree);
      const result = resolveDropPlacement(makeDropContext({
        activeParsed: { type: 'row', id: 11 },
        // over row-14 is in section 3, but maps show row-11 still in section 2
        // so the filtered list for section 3 won't contain row-11
        overParsed: { type: 'row', id: 14 },
        pointer: { x: 50, y: 350 },
        maps,
        sourceParentId: 2,
        sourceIndex: 0,
        overRect: { left: 0, top: 300, width: 100, height: 50 },
      }));

      // row-14 is found in section 3, pointer below center → after row-14
      expect(result).toEqual({ elementID: 11, targetParentId: 3, afterElementID: 14 });
    });
  });
});
```

**Step 2: Run tests to verify they fail**

Run: `npm run test -- --run client/src/tests/utils/resolveDropPlacement.test.ts`
Expected: FAIL — module `@/utils/resolveDropPlacement` does not exist

**Step 3: Write the implementation**

Create `client/src/utils/resolveDropPlacement.ts`:

```typescript
import { isContainerNode } from '@/types/elements';
import { buildDraggableId, parseDraggableId } from '@/types/dnd';
import type { ParsedDraggableId } from '@/types/dnd';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import { resolveReorderParams } from '@/utils/resolveReorderParams';
import type { ReorderElementParams } from '@/api/endpoints';

interface RectLike {
  readonly left: number;
  readonly top: number;
  readonly width: number;
  readonly height: number;
}

export interface DropContext {
  readonly activeParsed: ParsedDraggableId;
  readonly overParsed: ParsedDraggableId;
  readonly pointer: { readonly x: number; readonly y: number } | null;
  readonly maps: ElementMaps;
  readonly sourceParentId: number;
  readonly sourceIndex: number;
  readonly overRect: RectLike;
}

/**
 * Pure function that resolves a drag-and-drop event into API reorder parameters.
 *
 * Handles both same-container reordering and cross-container moves:
 * - Same container (sourceParentId === targetParentId): places at the over
 *   element's index without direction adjustment (SortableContext handles
 *   visual positioning).
 * - Cross container: applies pointer-based direction (before/after) relative
 *   to the over element's rect.
 *
 * Returns null for no-ops (same position) or invalid states (missing nodes).
 */
export function resolveDropPlacement(ctx: DropContext): ReorderElementParams | null {
  const { activeParsed, overParsed, pointer, maps, sourceParentId, sourceIndex, overRect } = ctx;

  let targetParentId: number;
  let insertIndex: number;

  const activeCompositeId = buildDraggableId(activeParsed.type, activeParsed.id);

  if (overParsed.type === activeParsed.type) {
    // Over a sibling — use the sibling's parent
    const overNode = maps.nodeMap.get(overParsed.id);
    if (!overNode) return null;

    targetParentId = overNode.parentId;
    const siblings = maps.childrenByParentId.get(targetParentId) ?? [];
    const compositeIds = siblings.map((n) => buildDraggableId(activeParsed.type, n.id));
    const filtered = compositeIds.filter((id) => id !== activeCompositeId);

    const overCompositeId = buildDraggableId(overParsed.type, overParsed.id);
    const overIdx = filtered.indexOf(overCompositeId);

    if (overIdx === -1) {
      insertIndex = filtered.length;
    } else {
      insertIndex = overIdx;

      // Apply direction for cross-container moves
      if (sourceParentId !== targetParentId && pointer !== null) {
        if (resolveInsertDirection(pointer, overRect, activeParsed.type) === 'after') {
          insertIndex += 1;
        }
      }
    }

    const clampedIndex = Math.min(insertIndex, filtered.length);
    filtered.splice(clampedIndex, 0, activeCompositeId);

    return resolveReorderParams({
      activeId: activeCompositeId,
      overContainerParentId: targetParentId,
      overIndex: filtered.indexOf(activeCompositeId),
      containerItems: filtered,
      sourceContainerParentId: sourceParentId,
      sourceIndex,
    });
  }

  // Over a container — drop into it
  const containerNode = maps.nodeMap.get(overParsed.id);
  if (!containerNode || !isContainerNode(containerNode)) return null;

  targetParentId = containerNode.id;
  const children = containerNode.children ?? [];
  const compositeIds = children.map((n) => buildDraggableId(activeParsed.type, n.id));
  const filtered = compositeIds.filter((id) => id !== activeCompositeId);

  insertIndex = filtered.length;
  filtered.splice(insertIndex, 0, activeCompositeId);

  return resolveReorderParams({
    activeId: activeCompositeId,
    overContainerParentId: targetParentId,
    overIndex: filtered.indexOf(activeCompositeId),
    containerItems: filtered,
    sourceContainerParentId: sourceParentId,
    sourceIndex,
  });
}
```

**Step 4: Run tests to verify they pass**

Run: `npm run test -- --run client/src/tests/utils/resolveDropPlacement.test.ts`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add client/src/utils/resolveDropPlacement.ts client/src/tests/utils/resolveDropPlacement.test.ts
git commit -m "feat: extract resolveDropPlacement pure function with tests"
```

---

### Task 2: Extract `usePendingTree` hook

**Files:**
- Create: `client/src/hooks/usePendingTree.ts`
- Create: `client/src/tests/hooks/usePendingTree.test.ts`

**Step 1: Write the failing tests**

Create `client/src/tests/hooks/usePendingTree.test.ts`:

```typescript
import { renderHook, act } from '@testing-library/react';
import { usePendingTree } from '@/hooks/usePendingTree';
import { buildMaps } from '@/hooks/useElementMaps';
import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
  ElementTreeResponse,
} from '@/types/elements';

// --- Minimal factories ---

function makeElement(id: number, parentId: number): SimpleElementNode {
  return {
    id, parentId, title: `Element ${id}`,
    blockSchema: { typeName: 'Element', label: 'Element', icon: 'font-icon-block-content', type: 'Element', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
  };
}

function makeColumn(id: number, children: SimpleElementNode[], parentId: number): ColumnNode {
  return {
    id, parentId, title: `Column ${id}`,
    blockSchema: { typeName: 'Column', label: 'Column', icon: 'font-icon-block-content', type: 'Column', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'column', allowedTypes: null, children, gridSettings: { md: { width: 6, offset: 0, visible: true } },
  };
}

function makeRow(id: number, children: ColumnNode[], parentId: number): RowNode {
  return {
    id, parentId, title: `Row ${id}`,
    blockSchema: { typeName: 'Row', label: 'Row', icon: 'font-icon-block-content', type: 'Row', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'row', allowedTypes: null, children,
  };
}

function makeSection(id: number, children: RowNode[], parentId: number): SectionNode {
  return {
    id, parentId, title: `Section ${id}`,
    blockSchema: { typeName: 'Section', label: 'Section', icon: 'font-icon-block-content', type: 'Section', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'section', allowedTypes: null, children,
  };
}

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
      const maps = buildMaps(testTree);

      act(() => {
        result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3,    // target: section 3
          14,   // after row-14
          testTree,
          maps,
        );
      });

      expect(result.current.pendingTree).not.toBeNull();
      expect(result.current.collisionRefs.hasPendingMoveRef.current).toBe(true);
      expect(result.current.collisionRefs.pendingContainerItemsRef.current).not.toBeNull();
    });

    it('returns new tree and maps on success', () => {
      const { result } = renderHook(() => usePendingTree());
      const maps = buildMaps(testTree);

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        moveResult = result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3,
          14,
          testTree,
          maps,
        );
      });

      expect(moveResult!).not.toBeNull();
      expect(moveResult!.tree).not.toBe(testTree);
      expect(moveResult!.maps).toBeDefined();
    });

    it('returns null when move is a no-op', () => {
      const { result } = renderHook(() => usePendingTree());
      const maps = buildMaps(testTree);

      let moveResult: ReturnType<typeof result.current.applyPendingMove>;
      act(() => {
        // Move row-11 after nothing in section 2 (already first in section 2)
        moveResult = result.current.applyPendingMove(
          { type: 'row', id: 11 },
          2,    // same parent
          null, // first position (same as current)
          testTree,
          maps,
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
          maps,
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
      const maps = buildMaps(testTree);

      act(() => {
        result.current.applyPendingMove(
          { type: 'row', id: 11 },
          3, 14, testTree, maps,
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
```

**Step 2: Run tests to verify they fail**

Run: `npm run test -- --run client/src/tests/hooks/usePendingTree.test.ts`
Expected: FAIL — module `@/hooks/usePendingTree` does not exist

**Step 3: Write the implementation**

Create `client/src/hooks/usePendingTree.ts`:

```typescript
import { useCallback, useMemo, useRef, useState } from 'react';
import type { RefObject } from 'react';
import type { ParsedDraggableId } from '@/types/dnd';
import { buildDraggableId } from '@/types/dnd';
import type { ElementTreeResponse } from '@/types/elements';
import type { ElementMaps } from '@/hooks/useElementMaps';
import { buildMaps } from '@/hooks/useElementMaps';
import { applyReorder } from '@/utils/applyReorder';
import type { OverRectSnapshot } from '@/utils/collisionDetection';

export interface CollisionRefs {
  readonly hasPendingMoveRef: RefObject<boolean>;
  readonly pendingContainerItemsRef: RefObject<ReadonlySet<string | number> | null>;
  readonly sourceContainerItemsRef: RefObject<ReadonlySet<string | number> | null>;
  readonly overRectRef: RefObject<OverRectSnapshot | null>;
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
    effectiveMaps: ElementMaps,
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

  const collisionRefs = useMemo<CollisionRefs>(() => ({
    hasPendingMoveRef,
    pendingContainerItemsRef,
    sourceContainerItemsRef,
    overRectRef,
  }), []);

  const applyPendingMove = useCallback((
    activeParsed: ParsedDraggableId,
    targetParentId: number,
    afterElementId: number | null,
    effectiveTree: ElementTreeResponse,
    effectiveMaps: ElementMaps,
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
  }, []);

  const setSourceSiblings = useCallback((siblings: ReadonlySet<string | number>) => {
    sourceContainerItemsRef.current = siblings;
  }, []);

  const getEffective = useCallback((
    canonicalTree: ElementTreeResponse,
    canonicalMaps: ElementMaps,
  ): { tree: ElementTreeResponse; maps: ElementMaps } => {
    if (pendingTreeRef.current !== null && pendingMapsRef.current !== null) {
      return { tree: pendingTreeRef.current, maps: pendingMapsRef.current };
    }
    return { tree: canonicalTree, maps: canonicalMaps };
  }, []);

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
```

**Step 4: Run tests to verify they pass**

Run: `npm run test -- --run client/src/tests/hooks/usePendingTree.test.ts`
Expected: All tests PASS

**Step 5: Commit**

```bash
git add client/src/hooks/usePendingTree.ts client/src/tests/hooks/usePendingTree.test.ts
git commit -m "feat: extract usePendingTree hook with tests"
```

---

### Task 3: Rewrite `useDragAndDrop` hook

**Files:**
- Modify: `client/src/hooks/useDragAndDrop.ts` (full rewrite)
- Modify: `client/src/tests/hooks/useDragAndDrop.test.tsx` (full rewrite)

**Step 1: Rewrite the hook**

Replace `client/src/hooks/useDragAndDrop.ts` with:

```typescript
import { createContext, useCallback, useContext, useMemo, useState } from 'react';
import {
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import type {
  CollisionDetection,
  DragCancelEvent,
  DragEndEvent,
  DragOverEvent,
  DragStartEvent,
  SensorDescriptor,
  SensorOptions,
} from '@dnd-kit/core';
import {
  buildDraggableId,
  parseDraggableId,
} from '@/types/dnd';
import type { DraggableType } from '@/types/dnd';
import { isContainerNode } from '@/types/elements';
import type {
  ElementNode,
  ElementTreeResponse,
} from '@/types/elements';
import { useElementMaps } from '@/hooks/useElementMaps';
import { usePendingTree } from '@/hooks/usePendingTree';
import { resolveDropPlacement } from '@/utils/resolveDropPlacement';
import { resolveInsertDirection } from '@/utils/resolveInsertDirection';
import { createTypedCollisionDetection } from '@/utils/collisionDetection';

// --- Public types ---

export interface DragState {
  activeId: string;
  activeType: DraggableType;
  activeNode: ElementNode;
}

export interface UseDragAndDropOptions {
  tree: ElementTreeResponse;
  onReorder: (
    elementID: number,
    targetParentId: number,
    afterElementID: number | null,
    clearPendingTree: () => void,
  ) => void;
}

export interface DndContextProps {
  sensors: SensorDescriptor<SensorOptions>[];
  collisionDetection: CollisionDetection;
  onDragStart: (event: DragStartEvent) => void;
  onDragOver: (event: DragOverEvent) => void;
  onDragEnd: (event: DragEndEvent) => void;
  onDragCancel: (event: DragCancelEvent) => void;
}

export interface UseDragAndDropReturn {
  /** Spread directly onto DndContext. */
  dndContextProps: DndContextProps;
  /** Current drag state for DragOverlay rendering. */
  dragState: DragState | null;
  /** Tree with pending cross-container move applied, or null. */
  pendingTree: ElementTreeResponse | null;
}

// --- Drag context ---

export interface DragContextValue {
  activeType: DraggableType | null;
}

export const DragContext = createContext<DragContextValue>({ activeType: null });

export function useDragContext(): DragContextValue {
  return useContext(DragContext);
}

// --- Helpers ---

/**
 * Compute the current pointer viewport position from a dnd-kit drag event.
 *
 * `active.rect.current.translated` is scroll-adjusted (viewport-relative) but
 * uses the original element's dimensions, so its center drifts from the pointer
 * when the grab point isn't at the element's center.
 *
 * Fix: offset `translated` by the grab point distance within the initial rect.
 * This gives the pointer's true viewport position, consistent with
 * `getBoundingClientRect()` values used for droppable rects.
 *
 * `event.delta` is NOT usable here — dnd-kit adds accumulated scroll offsets
 * to the translate state (via auto-scroll's `onScrollChange`), so
 * `pe.clientY + event.delta.y` gives a scroll-inflated value, not viewport Y.
 */
function getPointerPosition(event: {
  activatorEvent: Event;
  active: { rect: { current: { initial: { left: number; top: number } | null; translated: { left: number; top: number } | null } } };
}): { x: number; y: number } | null {
  const pe = event.activatorEvent;
  if (!(pe instanceof PointerEvent)) return null;

  const initialRect = event.active.rect.current.initial;
  const translated = event.active.rect.current.translated;
  if (!initialRect || !translated) return null;

  return {
    x: translated.left + (pe.clientX - initialRect.left),
    y: translated.top + (pe.clientY - initialRect.top),
  };
}

// --- Hook ---

const POINTER_DISTANCE_THRESHOLD = 8;

export function useDragAndDrop({
  tree,
  onReorder,
}: UseDragAndDropOptions): UseDragAndDropReturn {
  const [dragState, setDragState] = useState<DragState | null>(null);
  const maps = useElementMaps(tree);
  const pending = usePendingTree();

  const [collisionDetection] = useState<CollisionDetection>(
    () => createTypedCollisionDetection(pending.collisionRefs),
  );

  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: { distance: POINTER_DISTANCE_THRESHOLD },
    }),
  );

  const handleDragStart = useCallback(
    (event: DragStartEvent) => {
      const parsed = parseDraggableId(String(event.active.id));
      if (!parsed) return;

      const node = maps.nodeMap.get(parsed.id);
      if (!node) return;

      const siblings = maps.childrenByParentId.get(node.parentId) ?? [];
      pending.setSourceSiblings(new Set(
        siblings
          .filter((n) => n.id !== parsed.id)
          .map((n) => buildDraggableId(parsed.type, n.id)),
      ));

      setDragState({
        activeId: String(event.active.id),
        activeType: parsed.type,
        activeNode: node,
      });
    },
    [maps, pending],
  );

  const handleDragOver = useCallback(
    (event: DragOverEvent) => {
      const { active, over } = event;
      if (!over || active.id === over.id) return;

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) return;

      const { tree: effectiveTree, maps: effectiveMaps } = pending.getEffective(tree, maps);

      const activeNode = effectiveMaps.nodeMap.get(activeParsed.id);
      if (!activeNode) return;

      let targetParentId: number;
      let afterElementId: number | null;

      if (overParsed.type === activeParsed.type) {
        const overNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!overNode) return;
        targetParentId = overNode.parentId;

        const pointer = getPointerPosition(event);
        if (pointer !== null && resolveInsertDirection(pointer, over.rect, activeParsed.type) === 'before') {
          const siblings = effectiveMaps.childrenByParentId.get(targetParentId) ?? [];
          const overIdx = siblings.findIndex((n) => n.id === overParsed.id);
          afterElementId = overIdx > 0 ? siblings[overIdx - 1].id : null;
        } else {
          afterElementId = overParsed.id;
        }
      } else {
        const containerNode = effectiveMaps.nodeMap.get(overParsed.id);
        if (!containerNode || !isContainerNode(containerNode)) return;
        targetParentId = containerNode.id;
        const children = containerNode.children ?? [];
        afterElementId = children.length > 0 ? children[children.length - 1].id : null;
      }

      // Same-container: SortableContext handles visual reordering via transforms
      if (activeNode.parentId === targetParentId) return;

      pending.applyPendingMove(activeParsed, targetParentId, afterElementId, effectiveTree, effectiveMaps);
    },
    [tree, maps, pending],
  );

  const handleDragEnd = useCallback(
    (event: DragEndEvent) => {
      setDragState(null);

      const { active, over } = event;
      if (!over || active.id === over.id) {
        pending.clear();
        return;
      }

      const activeParsed = parseDraggableId(String(active.id));
      const overParsed = parseDraggableId(String(over.id));
      if (!activeParsed || !overParsed) {
        pending.clear();
        return;
      }

      const activeNode = maps.nodeMap.get(activeParsed.id);
      if (!activeNode) {
        pending.clear();
        return;
      }

      const sourceParentId = activeNode.parentId;
      const sourceChildren = maps.childrenByParentId.get(sourceParentId);
      if (!sourceChildren) {
        pending.clear();
        return;
      }
      const sourceIndex = sourceChildren.findIndex((n) => n.id === activeParsed.id);

      const { maps: effectiveMaps } = pending.getEffective(tree, maps);

      const overRectSnapshot = pending.collisionRefs.overRectRef.current;
      const effectiveOverRect = String(overRectSnapshot?.id) === String(over.id)
        ? overRectSnapshot!.rect
        : over.rect;

      const placement = resolveDropPlacement({
        activeParsed,
        overParsed,
        pointer: getPointerPosition(event),
        maps: effectiveMaps,
        sourceParentId,
        sourceIndex,
        overRect: effectiveOverRect,
      });

      if (placement) {
        onReorder(placement.elementID, placement.targetParentId, placement.afterElementID, pending.clear);
      } else {
        pending.clear();
      }
    },
    [tree, maps, onReorder, pending],
  );

  const handleDragCancel = useCallback(() => {
    setDragState(null);
    pending.clear();
  }, [pending]);

  const dndContextProps = useMemo<DndContextProps>(() => ({
    sensors,
    collisionDetection,
    onDragStart: handleDragStart,
    onDragOver: handleDragOver,
    onDragEnd: handleDragEnd,
    onDragCancel: handleDragCancel,
  }), [sensors, collisionDetection, handleDragStart, handleDragOver, handleDragEnd, handleDragCancel]);

  return {
    dndContextProps,
    dragState,
    pendingTree: pending.pendingTree,
  };
}
```

**Step 2: Rewrite the tests**

Replace `client/src/tests/hooks/useDragAndDrop.test.tsx` with the simplified version. Direction-aware tests now live in `resolveDropPlacement.test.ts`. The hook tests focus on event wiring and state transitions.

```typescript
import { renderHook, act } from '@testing-library/react';
import type {
  DragStartEvent,
  DragEndEvent,
  DragOverEvent,
  DragCancelEvent,
  Active,
  Over,
} from '@dnd-kit/core';
import type { MutableRefObject } from 'react';

// jsdom does not implement PointerEvent — polyfill with MouseEvent
if (typeof globalThis.PointerEvent === 'undefined') {
  (globalThis as Record<string, unknown>).PointerEvent = class PointerEvent extends MouseEvent {
    constructor(type: string, init?: PointerEventInit) {
      super(type, init);
    }
  };
}
import type {
  SimpleElementNode,
  ColumnNode,
  RowNode,
  SectionNode,
  ElementTreeResponse,
} from '@/types/elements';
import { useDragAndDrop } from '@/hooks/useDragAndDrop';
import type { DragState } from '@/hooks/useDragAndDrop';

// --- Factories ---

function makeElement(id: number, parentId: number): SimpleElementNode {
  return {
    id, parentId, title: `Element ${id}`,
    blockSchema: { typeName: 'Element', label: 'Element', icon: 'font-icon-block-content', type: 'Element', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
  };
}

function makeColumn(id: number, children: SimpleElementNode[], parentId: number): ColumnNode {
  return {
    id, parentId, title: `Column ${id}`,
    blockSchema: { typeName: 'Column', label: 'Column', icon: 'font-icon-block-content', type: 'Column', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'column', allowedTypes: null, children, gridSettings: { md: { width: 6, offset: 0, visible: true } },
  };
}

function makeRow(id: number, children: ColumnNode[], parentId: number): RowNode {
  return {
    id, parentId, title: `Row ${id}`,
    blockSchema: { typeName: 'Row', label: 'Row', icon: 'font-icon-block-content', type: 'Row', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'row', allowedTypes: null, children,
  };
}

function makeSection(id: number, children: RowNode[], parentId: number): SectionNode {
  return {
    id, parentId, title: `Section ${id}`,
    blockSchema: { typeName: 'Section', label: 'Section', icon: 'font-icon-block-content', type: 'Section', title: '', summary: '' },
    obsoleteClassName: null, version: 1, canDelete: true, canPublish: true, canUnpublish: false, canCreate: true, editLink: null, statusFlags: {},
    containerType: 'section', allowedTypes: null, children,
  };
}

// --- dnd-kit event factories (simplified — no rect geometry needed) ---

function createMutableRef<T>(value: T): MutableRefObject<T> {
  return { current: value };
}

function makeActive(id: string): Active {
  return {
    id,
    data: createMutableRef(undefined),
    rect: createMutableRef({ initial: null, translated: null }),
  };
}

function makeOver(id: string): Over {
  return {
    id,
    data: createMutableRef(undefined),
    rect: { width: 0, height: 0, top: 0, left: 0, right: 0, bottom: 0 },
    disabled: false,
  };
}

function makeDragStartEvent(activeId: string): DragStartEvent {
  return {
    active: makeActive(activeId),
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragEndEvent(activeId: string, overId: string | null): DragEndEvent {
  return {
    active: makeActive(activeId),
    over: overId !== null ? makeOver(overId) : null,
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragOverEvent(activeId: string, overId: string): DragOverEvent {
  return {
    active: makeActive(activeId),
    over: makeOver(overId),
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

function makeDragCancelEvent(activeId: string): DragCancelEvent {
  return {
    active: makeActive(activeId),
    over: null,
    collisions: [],
    delta: { x: 0, y: 0 },
    activatorEvent: new PointerEvent('pointerdown', { clientX: 0, clientY: 0 }),
  };
}

// --- Test trees ---

const testTree: ElementTreeResponse = {
  '42': [
    makeSection(1, [
      makeRow(10, [
        makeColumn(20, [makeElement(30, 20), makeElement(31, 20)], 10),
        makeColumn(21, [makeElement(32, 21)], 10),
      ], 1),
    ], 42),
  ],
};

const crossContainerTree: ElementTreeResponse = {
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

// --- Tests ---

describe('useDragAndDrop', () => {
  const defaultOptions = {
    tree: testTree,
    onReorder: vi.fn(),
  };

  afterEach(() => {
    vi.restoreAllMocks();
  });

  describe('dndContextProps', () => {
    it('returns sensors array', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      expect(result.current.dndContextProps.sensors).toBeDefined();
      expect(result.current.dndContextProps.sensors.length).toBeGreaterThan(0);
    });

    it('returns a collision detection function', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      expect(typeof result.current.dndContextProps.collisionDetection).toBe('function');
    });

    it('returns all required event handlers', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));
      const { dndContextProps } = result.current;
      expect(typeof dndContextProps.onDragStart).toBe('function');
      expect(typeof dndContextProps.onDragOver).toBe('function');
      expect(typeof dndContextProps.onDragEnd).toBe('function');
      expect(typeof dndContextProps.onDragCancel).toBe('function');
    });
  });

  it('initializes with null dragState', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.dragState).toBeNull();
  });

  it('initializes with null pendingTree', () => {
    const { result } = renderHook(() => useDragAndDrop(defaultOptions));
    expect(result.current.pendingTree).toBeNull();
  });

  describe('handleDragStart', () => {
    it('sets dragState for a valid element', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });

      const state: DragState = result.current.dragState!;
      expect(state).not.toBeNull();
      expect(state.activeId).toBe('element-30');
      expect(state.activeType).toBe('element');
      expect(state.activeNode.id).toBe(30);
    });

    it('sets dragState for a container node', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('column-20'));
      });

      const state = result.current.dragState!;
      expect(state.activeType).toBe('column');
      expect(state.activeNode.id).toBe(20);
    });

    it('does not set dragState for an invalid composite ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('invalid'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not set dragState for a non-existent node ID', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-999'));
      });

      expect(result.current.dragState).toBeNull();
    });
  });

  describe('handleDragEnd', () => {
    it('clears dragState', () => {
      const { result } = renderHook(() => useDragAndDrop(defaultOptions));

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(result.current.dragState).toBeNull();
    });

    it('does not call onReorder when over is null', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', null));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder when active === over (no-op)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-30'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('calls onReorder for a same-container sibling swap', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'element-31'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      expect(onReorder).toHaveBeenCalledWith(
        30, 20, 31, expect.any(Function),
      );
    });

    it('does not call onReorder for invalid active ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('invalid', 'element-31'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });

    it('does not call onReorder for invalid over ID', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('element-30', 'invalid'));
      });

      expect(onReorder).not.toHaveBeenCalled();
    });
  });

  describe('handleDragOver + handleDragEnd (cross-container integration)', () => {
    it('sets pendingTree on cross-container dragOver', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });

      expect(result.current.pendingTree).not.toBeNull();
    });

    it('calls onReorder after cross-container dragOver + dragEnd', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', 'row-14'));
      });

      expect(onReorder).toHaveBeenCalledTimes(1);
      // Verify the element moved to section 3
      expect(onReorder).toHaveBeenCalledWith(
        11, 3, expect.any(Number), expect.any(Function),
      );
    });

    it('does not set pendingTree for same-container dragOver', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-12'));
      });

      expect(result.current.pendingTree).toBeNull();
    });
  });

  describe('deferred pendingTree clearing', () => {
    it('pendingTree is NOT cleared until clearPendingTree callback is invoked', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', 'row-14'));
      });

      expect(result.current.pendingTree).not.toBeNull();
      expect(onReorder).toHaveBeenCalledTimes(1);

      const clearFn = onReorder.mock.calls[0][3] as () => void;
      act(() => {
        clearFn();
      });

      expect(result.current.pendingTree).toBeNull();
    });

    it('pendingTree IS cleared immediately on early return (no over)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('row-11', null));
      });

      expect(result.current.pendingTree).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('pendingTree IS cleared immediately on early return (invalid IDs)', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragEnd(makeDragEndEvent('invalid', 'row-14'));
      });

      expect(result.current.pendingTree).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });
  });

  describe('handleDragCancel', () => {
    it('clears dragState without calling onReorder', () => {
      const onReorder = vi.fn();
      const { result } = renderHook(() =>
        useDragAndDrop({ ...defaultOptions, onReorder }),
      );

      act(() => {
        result.current.dndContextProps.onDragStart(makeDragStartEvent('element-30'));
      });
      expect(result.current.dragState).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent('element-30'));
      });

      expect(result.current.dragState).toBeNull();
      expect(onReorder).not.toHaveBeenCalled();
    });

    it('clears pendingTree on cancel', () => {
      const { result } = renderHook(() =>
        useDragAndDrop({ tree: crossContainerTree, onReorder: vi.fn() }),
      );

      act(() => {
        result.current.dndContextProps.onDragOver(makeDragOverEvent('row-11', 'row-14'));
      });
      expect(result.current.pendingTree).not.toBeNull();

      act(() => {
        result.current.dndContextProps.onDragCancel(makeDragCancelEvent('row-11'));
      });

      expect(result.current.pendingTree).toBeNull();
    });
  });
});
```

**Step 3: Run tests**

Run: `npm run test -- --run client/src/tests/hooks/useDragAndDrop.test.tsx`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add client/src/hooks/useDragAndDrop.ts client/src/tests/hooks/useDragAndDrop.test.tsx
git commit -m "refactor: decompose useDragAndDrop into focused units

- handleDragEnd delegates to resolveDropPlacement (pure function)
- Ref management encapsulated in usePendingTree hook
- Public API groups DndContext props into dndContextProps object
- Tests simplified: direction-aware placement tested in resolveDropPlacement.test.ts"
```

---

### Task 4: Update consumer and exports

**Files:**
- Modify: `client/src/components/GridEditor/GridEditor.tsx`
- Modify: `client/src/hooks/index.ts`
- Modify: `client/src/tests/helpers/dndTestUtils.tsx` (if DragContext import path changed — verify)

**Step 1: Update GridEditor to use `dndContextProps`**

In `client/src/components/GridEditor/GridEditor.tsx`, replace the destructuring and DndContext props:

Change the destructuring (around line 35):
```typescript
// Before:
const { sensors, collisionDetection, dragState, pendingTree, handleDragStart, handleDragOver, handleDragEnd, handleDragCancel } = useDragAndDrop({

// After:
const { dndContextProps, dragState, pendingTree } = useDragAndDrop({
```

Change the DndContext usage (around line 74):
```typescript
// Before:
<DndContext
  sensors={sensors}
  collisionDetection={collisionDetection}
  measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
  onDragStart={handleDragStart}
  onDragOver={handleDragOver}
  onDragEnd={handleDragEnd}
  onDragCancel={handleDragCancel}
>

// After:
<DndContext
  {...dndContextProps}
  measuring={{ droppable: { strategy: MeasuringStrategy.Always } }}
>
```

**Step 2: Update hook exports**

In `client/src/hooks/index.ts`, update the exports:

```typescript
// Before:
export { useDragAndDrop } from './useDragAndDrop';
export type { DragState, UseDragAndDropOptions, UseDragAndDropReturn } from './useDragAndDrop';

// After:
export { useDragAndDrop } from './useDragAndDrop';
export type { DragState, DndContextProps, UseDragAndDropOptions, UseDragAndDropReturn } from './useDragAndDrop';
export { usePendingTree } from './usePendingTree';
export type { UsePendingTreeReturn, CollisionRefs } from './usePendingTree';
```

**Step 3: Verify dndTestUtils import**

Check `client/src/tests/helpers/dndTestUtils.tsx` — it imports `DragContext` from `@/hooks/useDragAndDrop`. This import is unchanged (DragContext is still exported from the same file). No changes needed.

**Step 4: Run all JS tests**

Run: `npm run qa`
Expected: lint + typecheck + all tests PASS

**Step 5: Commit**

```bash
git add client/src/components/GridEditor/GridEditor.tsx client/src/hooks/index.ts
git commit -m "refactor: update GridEditor to use dndContextProps spread"
```

---

### Task 5: Full QA

**Step 1: Run full JS QA**

Run: `npm run qa`
Expected: lint + typecheck + all tests PASS

**Step 2: Run mutation testing to verify coverage quality**

Run: `npm run mutate`
Expected: No significant MSI regression from baseline

**Step 3: If Docker is running, run PHP QA + E2E**

Run: `make qa && make test-e2e`
Expected: All pass (no PHP or E2E changes, but verifies nothing broke in the build)
