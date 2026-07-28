---
name: dnd-guide
description: Reference guide for the grid editor's drag-and-drop system built on dnd-kit. Use when modifying, debugging, or extending any drag-and-drop behavior — collision detection, reorder logic, cross-container moves, pending tree state, optimistic updates, drop placement, or the backend reorder pipeline. Also use when touching any file in the DnD file map, writing E2E tests for drag operations, or when the user mentions dragging, dropping, sorting, reordering, or moving elements between containers. This system has been through 16+ iterations of bug fixes around coordinate spaces, timing, and edge cases — the skill captures that knowledge so you don't repeat the mistakes.
---

# Drag & Drop System Guide

This system has three sources of complexity that interact in subtle ways: **coordinate spaces** (three of them, easily mixed), **two fundamentally different code paths** (same-container vs cross-container), and **timing-sensitive state transitions** (pending tree → optimistic update). Every bug in this system's history traces back to one of these three. This skill teaches you to reason about them.

## The Two Code Paths

The DnD system takes **completely different paths** for same-container and cross-container moves. Knowing which path you're on determines which collision tier is active, whether direction detection matters, and which coordinate spaces are in play.

| Aspect | Same-container | Cross-container |
|--------|---------------|-----------------|
| Drag-time visual feedback | SortableContext CSS transforms | Pending tree (new DOM order) |
| Drop-time pre-positioning | **Pending tree** (set at drag-end) | **Pending tree** (already set from drag-over) |
| Collision tier | Tier 1: `centerCrossing` | Tier 2: `closestCenterLive` |
| Drop placement | **Index-based** (no direction detection) | **Direction-based** (pointer vs element center) |
| Coordinate space risk | Low (no CSS transform divergence) | **High** (DOM vs dnd-kit vs viewport) |
| `overRectRef` captured? | Yes | Yes — `handleDragMove` reads the live node for the preview direction; the drop-end fallback still pairs `getPointerPosition()` with `over.rect` (pre-transform, may lag the pending-tree re-render) |
| API payload | Same parentId, new sort position | New parentId + afterElementID |
| Code path in resolveDropPlacement | Lines 58-67 (index branch) | Lines 69-85 (direction branch) |

**This split is the first question to answer when investigating any DnD bug**: is it a same-container or cross-container move? The answer narrows the search space immediately.

**The split is drag-time only — drop-time is unified.** The two paths differ in *live* feedback because dnd-kit natively animates intra-container sorting (CSS transforms) but has no native cross-container move (hence the pending tree). But at **drop** both paths converge: `handleDragEnd` populates the pending tree with the final reordered tree (cross-container already did this during drag-over; same-container does it at drag-end, since transforms — not the pending tree — drove its live preview). This is load-bearing, not cosmetic — see invariant #8 and the drop-animation snap symptom in the Diagnostic Map. If you ever "simplify" same-container to skip the pending tree at drop and rely on the optimistic cache write instead, the drop animation will snap.

## The Three Coordinate Spaces

Nearly every DnD bug traces back to mixing coordinate spaces. Three spaces coexist during a drag:

| Space | Source | CSS transforms? | Auto-scroll? |
|-------|--------|-----------------|--------------|
| **dnd-kit measuring** | `droppableRects`, `over.rect`, `active.rect` | Stripped | Via re-measurement |
| **Viewport (DOM)** | `getBoundingClientRect()`, raw pointer events | Included | Implicitly |
| **dnd-kit sensor** | `collisionRect`, `pointerCoordinates`, `getPointerPosition()` | N/A | Adjusted by dnd-kit |

### Decision Matrix

Use this to determine which values to compare:

| Situation | Pointer source | Rect source | Why |
|-----------|---------------|-------------|-----|
| Drop-time direction (any path) | `getPointerPosition()` | `over.rect` | Both dnd-kit space — coordinate-safe, but `over.rect` is **pre-transform** and may not match visual position after pending tree re-render shifts the target via CSS transforms |
| Tier 1 collision detection | `pointerCoordinates` (overlap gate) | `droppableRects` (threshold) | Both from dnd-kit, but transforms cause mild mismatch — acceptable |
| Tier 2 collision detection | `collisionRect` center | `getBoundingClientRect()` | Both include CSS transforms — live rects needed because `droppableRects` are stale |
| Tier 3 parent containment | `pointerCoordinates` | `droppableRects` | Parent rects unaffected by child transforms |

**The auto-scroll trap**: During auto-scroll, dnd-kit adjusts sensor values (pointer, collisionRect) to account for scroll distance. But `getBoundingClientRect()` shifts in the **opposite direction** — the element moves within the viewport. Comparing dnd-kit sensor values against live DOM rects during auto-scroll gives wrong signs. This is why the drop-end fallback pairs `getPointerPosition()` with `over.rect` (both dnd-kit space) for direction, using the captured live node only for axis resolution.

**Column exception**: Narrow columns compare on the X-axis (see `resolveDropAxis` — full-width columns compare on Y). Auto-scroll is typically vertical, so those X-axis comparisons between spaces are safe. But the current code uses `over.rect` for all pending-path drops regardless of axis — simpler and avoids edge cases.

## System Invariants

These are the load-bearing constraints. Violating any of them causes a bug. Check these first when debugging.

1. **Coordinate space matching**: At drop time, pointer and over rect must be in the same coordinate space. `getPointerPosition()` + `over.rect` (both dnd-kit) is the only safe combination for direction detection.

2. **Pending tree clearing order**: `setQueryData` must execute before `clearPendingTree()` in `onMutate`. The `await cancelQueries()` creates a timing gap — any state change between the await and `setQueryData` can cause a 1-frame snap-back.

3. **Active item exclusion**: dnd-kit v6 does NOT exclude the active item from `droppableContainers`. The collision factory must filter it out to prevent no-op drops.

4. **Source depletion guard**: The pointer-inside-source-sibling guard must check `sourceItems.size > 0` before firing. On empty sources, it must fall through to allow cross-container detection.

5. **`overRectRef` capture and consumption**: Every tier's winning collision is captured via `captureWinnerNode` — the container comes straight from the collision's `data.droppableContainer` (all collisions produced here, and by dnd-kit's `closestCenter`, carry it). What's stored is the winner's DOM **node reference**, never a rect snapshot; consumers dereference it and call `getBoundingClientRect()` at comparison time, and only when the snapshot's id matches `over.id`. `handleDragMove` uses the live rect for the preview direction (over.rect lags the pending-tree re-render by a cycle and would invert the direction near a boundary); `handleDragEnd`'s no-preview fallback uses the live node for **axis resolution only** and pairs `getPointerPosition()` with `over.rect` for direction (both dnd-kit space — a live rect there would break under auto-scroll). See `captureWinnerNode` in `collisionDetection.ts` and the `liveOverNode` logic in `useDragAndDrop.ts`.

6. **`effectiveData` fallback**: Components render `pendingTree ?? data`. If `pendingTree` becomes null while `data` is stale, the UI snaps back. This is why clearing order matters.

7. **Type filtering hierarchy**: Collision detection filters droppables to same-type (siblings) and parent-type (one level up via `PARENT_CONTAINER_TYPE`). This prevents most structurally invalid drops but does NOT enforce business rules (e.g., Section inside Column) — those are backend-only.

8. **Drop-animation pre-positioning must use React state, never the query cache**: dnd-kit's `DragOverlay` drop animation measures the *real* dragged node's resting rect (`getBoundingClientRect()`) in a **layout effect** that fires immediately after the drag-end commit — and dnd-kit invokes `onDragEnd` inside the **same `unstable_batchedUpdates`** as its own `active→null` dispatch (`@dnd-kit/core` `AnimationManager` + `DndContext` drag-end handler). So whatever moves the dragged node to its final slot must be a React state update *in that commit*. The pending tree (`useState`) qualifies; `setQueryData` does **not** — TanStack's `notifyManager` defers query-observer re-renders via `systemSetTimeoutZero` (`setTimeout(cb, 0)`, a macrotask), so a cache write lands in a later commit, after the animation has already measured. This is why `handleDragEnd` pre-positions via `pending.applyPendingMove(...)` before firing the reorder mutation. **Corollary**: never assume `setQueryData` produces a same-commit re-render — it doesn't. Any drop-time visual correctness must ride the pending tree.

## Diagnostic Map

When you see a symptom, start here.

| Symptom | Most likely cause | Check first | Files |
|---------|------------------|-------------|-------|
| **Element lands on wrong side of target** | Drop-time `overRect` doesn't match visual position. Two variants: (a) the `overRectRef` snapshot's id doesn't match `over.id` (or the node unmounted) so the code fell back to `over.rect`, which is pre-transform and lags the pending-tree re-render, (b) `overRectRef` was read while CSS transforms shifted the element (same-container edge) | Which code path? For cross-container: check the `liveOverNode` condition (`overSnapshot.id === over.id`) in `useDragAndDrop.ts`. For same-container: check if `overRectRef` was captured with transforms active in `collisionDetection.ts:captureWinnerNode` | `useDragAndDrop.ts` (liveOverNode logic), `collisionDetection.ts` (captureWinnerNode), `resolveInsertDirection.ts` |
| **Ghost jump on drag start** | Collision detection fires immediately without threshold crossing | Is `centerCrossing` being used? Is the overlap gate working? Was it replaced with `closestCenter`? | `collisionDetection.ts` (centerCrossing) |
| **1-frame snap-back on drop** | Pending tree cleared before optimistic cache update | Is `setQueryData` before `clearPendingTree`? Is there an `await` between them? | `useElementMutations.ts` (onMutate ordering) |
| **Dropped item animates to its OLD slot, then snaps to the correct position after the animation** | The dragged node isn't at its final DOM position when dnd-kit's drop animation measures it. The final position is being driven by the optimistic cache write (`setQueryData`), which re-renders a macrotask too late (`setTimeout(0)` notify) — so the animation targets the pre-move slot. Almost always a **same-container** move that skipped pending-tree pre-positioning. | Does `handleDragEnd` call `pending.applyPendingMove(...)` before `onReorder` (invariant #8)? Is the pending tree populated at drop time for this path? | `useDragAndDrop.ts` (handleDragEnd pre-position), `usePendingTree.ts` |
| **Drop silently fails (no reorder)** | `over` is null at drop time — collision detection lost track | Check `hadSiblingHit` fallback, source depletion handling, pointer-inside-source guard | `collisionDetection.ts` (factory closure state) |
| **Element snaps to wrong container** | Parent-container fallback biased by `closestCenter` | Is containment-first check working? Is pointer inside a parent rect? | `collisionDetection.ts` (tier 3) |
| **Cross-container drag doesn't show element in target** | Pending tree not applied | Is `handleDragOver` detecting the cross-container move? Is `applyPendingMove` called? | `useDragAndDrop.ts` (handleDragOver), `usePendingTree.ts` |
| **Backend rejects a valid move** | Hierarchy validation too strict | Check `ContainerType::isChildAllowed()` and `ReorderValidator::checkHierarchyRules()` | `ContainerType.php`, `ReorderValidator.php` |
| **Optimistic update doesn't rollback on error** | Snapshot not captured or not restored | Is `onMutate` returning snapshot? Is `onError` calling `setQueryData(snapshot)`? | `useElementMutations.ts` |
| **E2E test flaky — works sometimes** | Missing settlement wait between operations | Is `waitForMutationSettlement()` called after each drop? Are there enough pointer steps (20+)? | `tests/E2E/helpers/drag.ts`, the failing spec |
| **Cross-zone drag succeeds (shouldn't)** | Zones share a `DndContext` | Each zone should have its own `GridEditorField` and `DndContext` | `GridEditorField`, component tree |

## Architecture

```
User drags element
  │
  ├─ handleDragStart ─── record source siblings, set drag state
  │
  ├─ handleDragOver ──── cross-container? → applyPendingMove (visual feedback)
  │                      same-container?  → SortableContext handles CSS transforms
  │
  ├─ collision detection (3-tier, runs on every pointer move)
  │
  └─ handleDragEnd
       ├─ resolveDropPlacement (same-container: index | cross-container: direction)
       ├─ applyPendingMove (pre-position dragged node into final slot — both paths;
       │                    React state, batches into dnd-kit's drag-end commit so the
       │                    drop animation measures the destination — see invariant #8)
       └─ onReorder → TanStack Query mutation
            ├─ onMutate: snapshot → applyReorder → setQueryData → clearPendingTree
            ├─ PATCH /api/reorder → ReorderValidator → ReorderExecutor
            ├─ onError: clearPendingTree (safety net) → restore snapshot → toast
            └─ onSettled: invalidateQueries (refetch authoritative tree)
```

## Collision Detection (3-Tier)

Read `references/collision-detection.md` for full algorithm details including the `hadSiblingHit` state machine, overlap gate margins, and grab-point offset correction.

| Tier | Algorithm | When | Why it exists |
|------|-----------|------|---------------|
| 1 | `centerCrossing` | Siblings, no pending move | Prevents ghost jumps via threshold crossing + overlap gate |
| 2 | `closestCenterLive` | Siblings, pending move active | CSS transforms make `droppableRects` stale — reads live DOM rects |
| 3 | Parent container fallback | No sibling collision | **Containment-first**, then `closestCenter`. Fixes bias toward smaller containers |

### Performance Contract

Collision detection runs on every pointer move (60Hz+), so the hot path avoids per-cycle work that doesn't change the outcome. These decisions are deliberate — don't "restore" the naive versions:

- **Winner-only returns**: `centerCrossing` and `closestCenterLive` return at most ONE collision, selected in a single pass (containment first, then squared center distance) — no sorting. Safe because dnd-kit derives `over` from `collisions[0]` (`getFirstCollision`) and nothing else consumes the array: only `PointerSensor` is wired (no `KeyboardSensor`/`sortableKeyboardCoordinates`, which runs its own `closestCorners` anyway), and no handler reads `event.collisions`. **Revisit if either of those changes.**
- **Cached composite-ID parsing**: `parseDraggableId` in `types/dnd.ts` memoizes per unique ID (module-level `Map`; all `ParsedDraggableId` fields are readonly, so instances are shared safely). `getDraggableType`, the collision filters, and the drag handlers all inherit the cache — each unique ID is parsed once per session instead of several times per pointer move.
- **`applyReorder` takes caller-provided maps**: its signature is `(tree, maps, elementKey, parentKey, afterKey)` and `maps` MUST be built from that exact `tree`. The drag path already holds the matching pair (`getEffective` returns tree+maps together), so the frequent no-op exit — hit on every pointer move while a cross-container preview hovers an unchanged slot — costs no tree walk. Don't "simplify" by rebuilding maps inside `applyReorder`.
- **No id→container index**: `captureWinnerNode` reads the winner's container from `collisions[0].data.droppableContainer` (present on every collision, including dnd-kit's `closestCenter` output). Don't add a per-cycle lookup Map for this.
- **Droppable measuring uses the default `WhileDragging` strategy**: per dnd-kit source, the strategy is only consulted when NOT dragging — during a drag, registry changes trigger full re-measures identically under every strategy. `MeasuringStrategy.Always` (used historically) only added idle-time re-measures of every droppable on each tree change, so it was removed.
- **Rejected: pre-filtering droppables via per-droppable `disabled` by active drag type.** It would shrink what dnd-kit hands the detector, but flipping `disabled` at drag start re-renders every registered block at the moment responsiveness matters most, churns the droppable registry (triggering a full re-measure mid-drag), and can't replace the per-tier filtering anyway (tiers 1/2 need same-type, tier 3 needs parent-type — both sets must stay enabled). Per-cycle filtering after ID-parse caching is cheap; don't re-litigate this without profiling data.

### Source Depletion

When dragging the **only** item from a container, `sourceContainerItemsRef` becomes empty. The system falls back to ALL same-type siblings so `centerCrossing` can detect target container siblings directly. Without this, only the parent-container fallback fires, placing items at container end rather than at the pointer's precise position. The pointer-inside-source-sibling guard must not fire on empty sources (`sourceItems.size > 0` check) — this was a real bug that caused silent drop failures.

## Pending Tree & Optimistic Update

### State transitions during a cross-container drop

| Step | `pendingTree` | Query cache (`data`) | `effectiveData` renders | Risk |
|------|--------------|---------------------|------------------------|------|
| During drag | Row in target | Row in source | Pending tree (correct) | — |
| `mutate()` called | Row in target | Row in source | Pending tree (correct) | — |
| `await cancelQueries()` yields | Row in target | Row in source | Pending tree (correct) | **Timing gap** — React can flush renders here |
| `setQueryData(optimistic)` | Row in target | **Row in target** | Pending tree (correct) | — |
| `clearPendingTree()` | **null** | Row in target | Cache data (correct) | Safe because cache already has correct data |

**The danger**: If `clearPendingTree` executes before `setQueryData` (or during the `await` gap), step 5 would show cache data = "row in source" = snap-back.

### The `clearPendingTree` safety net

`clearPendingTree` is called in both `onMutate` (line 114, normal path) and `onError` (line 121, safety net). The `onError` call handles the edge case where `onMutate` throws before reaching its own `clearPendingTree` (e.g., if `cancelQueries` fails). Since `clear()` is idempotent, calling it twice is harmless.

## Direction-Aware Drop Placement

- `resolveInsertDirection()` compares pointer vs element center
- **The axis is geometric, not type-based** (`resolveDropAxis`): a target spanning ≥ 95% of its parent container's content width (nearest `[data-dnd-container]` ancestor) compares on **Y** (above/below); narrower targets compare on **X** (left/right). Sections, rows, and content elements always render full-width → Y; only columns vary — a 12/12 column gets Y, narrower columns get X. Measurement failure (no live node / no marked ancestor / zero-width wrapper) degrades to the legacy type rule: column → X, else Y.
- **Same-container moves skip direction entirely** — index-based placement from SortableContext
- Cross-container moves compute `afterElementID` via direction + `resolveReorderParams()`
- The `afterElementID` API contract (null = first position) replaced an ambiguous index-based API

## Zone Isolation

Zone isolation is **implicit**: each zone renders its own `DndContext`, making cross-zone drags geometrically impossible. The backend enforces zone boundaries if a direct API call bypasses the frontend (HTTP 422).

## Backend

The reorder pipeline (`ReorderValidator → ReorderExecutor → ElementPersistenceService`) returns `Result` objects — `Result::ok()` / `Result::fail()`, never exceptions for validation failures. Frontend type filtering prevents most invalid drops, but hierarchy rules (Section cannot go inside Column) are enforced server-side only. On rejection, `onError` restores the snapshot (instant rollback), and `onSettled` refetches (authoritative confirmation).

## File Map

| File | Responsibility |
|------|---------------|
| `client/src/js/hooks/useDragAndDrop.ts` | Orchestrator: sensors, event handlers, `getPointerPosition()` |
| `client/src/js/hooks/usePendingTree.ts` | Pending tree state + `CollisionRefs` for cross-container drags |
| `client/src/js/utils/collisionDetection.ts` | 3-tier collision detection, type filtering, `centerCrossing`, `closestCenterLive` |
| `client/src/js/utils/applyReorder.ts` | Pure immutable tree mutation for optimistic updates |
| `client/src/js/utils/resolveDropPlacement.ts` | Same-container: index (L58-67) / Cross-container: direction (L69-85) |
| `client/src/js/utils/resolveInsertDirection.ts` | Pointer vs rect center on a given `DropAxis` → `'before' \| 'after'` |
| `client/src/js/utils/resolveDropAxis.ts` | Rendered-geometry axis decision (full-width → Y, narrower → X; legacy type rule as degraded fallback) |
| `client/src/js/utils/resolveReorderParams.ts` | dnd-kit context → API payload (`afterElementID`) |
| `client/src/js/hooks/useElementMaps.ts` | O(1) lookup maps: `nodeMap`, `childrenByParentId` |
| `client/src/js/hooks/useElementMutations.ts` | TanStack Query mutation: optimistic update, rollback, toast |
| `client/src/js/types/dnd.ts` | Composite IDs (`type-numericId`), `PARENT_CONTAINER_TYPE` hierarchy |
| `client/src/js/components/GridEditor/EditableGridEditor.tsx` | `DndContext` owner (`effectiveData`/pending-tree wiring now lives in `client/src/js/components/GridEditor/useGridEditorDnd.ts`) |
| `src/Validation/ReorderValidator.php` | Hierarchy enforcement via `ContainerType::isChildAllowed()` |
| `src/Service/ReorderExecutor.php` | Sort calculation, dirty tracking, cross-parent reindex |

## References

- `references/collision-detection.md` — Full algorithm details: `centerCrossing` threshold math, `hadSiblingHit` state machine, `overRectRef` capture rules, coordinate space interactions
- `references/e2e-testing.md` — Non-negotiable timing requirements, helper patterns, positioning strategies, journey test rules, common pitfalls

## Common Modification Patterns

### Adding a new drag constraint
Read `references/collision-detection.md` first. Modify `filterSiblings()`/`filterParentContainers()` for type-level constraints, or add logic within `centerCrossing`/the factory closure for behavior constraints.

### Changing drop placement logic
Check which code path (same-container or cross-container) is affected — they branch at `resolveDropPlacement.ts` line 58. The axis (X vs Y) is decided by `resolveDropAxis` from rendered geometry and passed into `resolveInsertDirection`; both call sites (`handleDragMove` preview, `handleDragEnd` fallback) must derive it from the same live node. Always test both paths separately.

### Adding a new sortable level
1. Add type to `DraggableType` and `PARENT_CONTAINER_TYPE` in `dnd.ts`
2. Add `SortableContext` in the parent component
3. Register children via `useSortable()`
4. Extend `getMetaLabel` in `DragOverlayContent` if the new type needs a child-count meta line (icon + title render for every type by default)
5. Add E2E tests: same-container reorder, cross-container move, source depletion, cancel

### Writing E2E tests for drag operations
Read `references/e2e-testing.md`. Missing a 500ms settlement wait or using fewer than 20 pointer steps causes intermittent failures from stale collision rects.

### Modifying the optimistic update
Check the state transition table above. The `await cancelQueries()` timing gap is the danger zone — any state change between the await and `setQueryData` causes visual flicker. Test with slow network throttling.
