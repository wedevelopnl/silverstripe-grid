# Design: useDragAndDrop Hook Decomposition

**Date:** 2026-03-11
**Status:** Approved
**Approach:** B — Decompose into focused hooks + pure function extraction

## Problem

`useDragAndDrop` is a 440-line monolith mixing pointer geometry, tree mutation, insertion index math, and ref coordination. The test file (1100 lines) needs elaborate event factories with pixel-level rect geometry to verify reorder logic — a symptom of missing abstraction boundaries.

Specific pain points:

- **Duplicated insertion logic** — `handleDragEnd` has two parallel code paths (pending tree vs same-container), each building composite IDs, filtering, splicing, and calling `resolveReorderParams`
- **Ref soup** — 6 mutable refs coordinating state between collision detection and callbacks
- **Test complexity** — Tests simulate pointer geometry just to verify placement logic that should be testable with plain objects

## Design

### 1. `resolveDropPlacement` — pure function

Extracts all insertion logic into a single pure function. No React, no dnd-kit event types.

```typescript
// client/src/utils/resolveDropPlacement.ts

interface DropContext {
  activeParsed: ParsedDraggableId;
  overParsed: ParsedDraggableId;
  pointer: { x: number; y: number } | null;
  maps: ElementMaps;
  sourceParentId: number;
  sourceIndex: number;
  overRectSnapshot: OverRectSnapshot | null;
  overRect: RectLike;
}

interface DropPlacement {
  elementID: number;
  targetParentId: number;
  afterElementID: number | null;
}

function resolveDropPlacement(ctx: DropContext): DropPlacement | null;
```

Responsibilities:
- Determine `targetParentId` (sibling's parent vs container's own ID)
- Resolve insert direction via pointer position (reuses `resolveInsertDirection`)
- Build ordered ID list, filter, splice
- Delegate to `resolveReorderParams` for no-op detection
- Return null for no-ops or invalid states

Eliminates the two parallel code paths in `handleDragEnd`.

### 2. `usePendingTree` — ref + state encapsulation

Owns all 6 refs and the pending tree React state behind a clean API.

```typescript
// client/src/hooks/usePendingTree.ts

interface UsePendingTreeReturn {
  pendingTree: ElementTreeResponse | null;

  collisionRefs: {
    hasPendingMoveRef: RefObject<boolean>;
    pendingContainerItemsRef: RefObject<ReadonlySet<string | number> | null>;
    sourceContainerItemsRef: RefObject<ReadonlySet<string | number> | null>;
    overRectRef: RefObject<OverRectSnapshot | null>;
  };

  applyPendingMove(
    activeParsed: ParsedDraggableId,
    targetParentId: number,
    afterElementId: number | null,
    effectiveTree: ElementTreeResponse,
    effectiveMaps: ElementMaps,
  ): { tree: ElementTreeResponse; maps: ElementMaps } | null;

  setSourceSiblings(siblings: ReadonlySet<string | number>): void;

  getEffective(canonicalTree: ElementTreeResponse, canonicalMaps: ElementMaps): {
    tree: ElementTreeResponse;
    maps: ElementMaps;
  };

  clear(): void;
}
```

Does not know about dnd-kit events, pointer positions, or insertion logic.

### 3. `useDragAndDrop` — thin orchestrator (~80 lines)

Wires dnd-kit events to the extracted logic.

```typescript
// client/src/hooks/useDragAndDrop.ts

interface UseDragAndDropReturn {
  dndContextProps: {
    sensors: SensorDescriptor<SensorOptions>[];
    collisionDetection: CollisionDetection;
    onDragStart: (event: DragStartEvent) => void;
    onDragOver: (event: DragOverEvent) => void;
    onDragEnd: (event: DragEndEvent) => void;
    onDragCancel: (event: DragCancelEvent) => void;
  };
  dragState: DragState | null;
  pendingTree: ElementTreeResponse | null;
}
```

- `handleDragEnd` becomes ~30 lines: parse IDs, get effective tree, call `resolveDropPlacement`, invoke `onReorder` or `clear`
- `handleDragOver` delegates tree mutation to `pending.applyPendingMove()`
- `getPointerPosition` stays as a private helper (event-specific)

Consumer changes: `GridEditor` uses `<DndContext {...dnd.dndContextProps}>` instead of passing 6 props individually.

### 4. Test architecture

| Test file | Scope | Needs React? | Needs rects? |
|-----------|-------|-------------|-------------|
| `resolveDropPlacement.test.ts` | Direction-aware placement, cross-container logic, edge cases | No | Yes (plain objects) |
| `usePendingTree.test.ts` | State + ref management | Yes (renderHook) | No |
| `useDragAndDrop.test.ts` | Wiring: events → onReorder called | Yes (renderHook) | Minimal |

Tests that move from `useDragAndDrop.test.ts` to `resolveDropPlacement.test.ts`:
- All direction-aware placement tests (~200 lines)
- Cross-container placement detail tests

Tests that stay in `useDragAndDrop.test.ts` (simplified):
- dragStart sets dragState
- dragOver triggers pendingTree
- dragEnd calls onReorder (without asserting specific afterElementID based on geometry)
- dragCancel clears state
- deferred pendingTree clearing

## Files Changed

| File | Action | ~Lines |
|------|--------|--------|
| `client/src/utils/resolveDropPlacement.ts` | New | ~80 |
| `client/src/hooks/usePendingTree.ts` | New | ~80 |
| `client/src/hooks/useDragAndDrop.ts` | Rewrite | ~80 |
| `client/src/tests/utils/resolveDropPlacement.test.ts` | New | ~200 |
| `client/src/tests/hooks/usePendingTree.test.ts` | New | ~100 |
| `client/src/tests/hooks/useDragAndDrop.test.ts` | Rewrite | ~200 |
| `client/src/components/GridEditor/GridEditor.tsx` | Update consumer | ~5 lines |
| `client/src/hooks/index.ts` | Update exports | ~3 lines |

No changes to: `collisionDetection.ts`, `applyReorder.ts`, `resolveReorderParams.ts`, `resolveInsertDirection.ts`, child block components.

## Constraints

- Public behavior must remain identical — all existing E2E tests pass without modification
- `DragContext` and `useDragContext` stay in `useDragAndDrop.ts`
- Collision detection system is out of scope for this refactor
