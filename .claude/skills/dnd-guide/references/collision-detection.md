# Collision Detection Deep Dive

Read this before modifying `client/src/utils/collisionDetection.ts`. This code went through 6+ iterations of bug fixes. Each decision has a reason — changing one part without understanding the others will likely reintroduce a fixed bug.

## Table of Contents

1. [Factory and State](#factory-and-state)
2. [Tier 1: centerCrossing](#tier-1-centercrossing)
3. [Tier 2: closestCenterLive](#tier-2-closestcenterlive)
4. [Tier 3: Parent Container Fallback](#tier-3-parent-container-fallback)
5. [Type Filtering](#type-filtering)
6. [overRectRef Capture](#overrectref-capture)
7. [Source Sibling Depletion](#source-sibling-depletion)
8. [The hadSiblingHit State Machine](#the-hadsiblinghit-state-machine)
9. [Coordinate Spaces](#coordinate-spaces)

## Factory and State

`createTypedCollisionDetection(options)` returns a closure with mutable state:

- `hadSiblingHit` — whether `centerCrossing` has ever detected a sibling during this drag
- `lastSourceItems` — previous `sourceContainerItemsRef.current` reference; when it changes (new drag), `hadSiblingHit` resets

The closure runs on every pointer move and decides which tier to use.

**Important**: dnd-kit v6 does NOT exclude the active item from `droppableContainers`. The factory filters it out explicitly to prevent no-op drops where the closest target is the item's own ghost.

## Tier 1: centerCrossing

**When**: No pending cross-container move (`hasPendingMoveRef.current === false`), checking same-container siblings only.

**Why it exists**: Replaced `closestCenter` (which caused ghost jumps at close proximity) and `pointerWithin` (which had different issues). The threshold-crossing approach prevents swaps until the drag has actually moved past a meaningful point on the target.

**Algorithm for each sibling droppable**:

1. **Threshold placement** (direction-aware):
   - Dragging toward target: threshold at target's near edge + half collision rect size
   - Dragging away: stricter of edge-based threshold and target center
   - This adapts to DragOverlay size asymmetry — compact overlay (~53px) vs full element (~350px)

2. **Position advance** (grab-point correction):
   - Uses the position furthest along the drag direction from either pointer or collision rect center
   - Grabbing a column header at its left edge shifts the collision rect center rightward, helping rightward drags but hurting leftward ones. Max-advance ensures threshold is reachable regardless of grab offset.

3. **Threshold crossing check**: Has the effective position crossed the threshold since drag started?

4. **Overlap gate** (axis-dependent margins):
   - Y: 150px — accommodates auto-scroll drift (~60px observed in 10 cycles)
   - X: 50px — prevents matching elements in adjacent columns (gaps typically 100px+)
   - Uses pointer coordinates (viewport-relative), NOT collision rect center

**Why no proximity gate instead?** Two coordinate-space reasons:
- `droppableRects` strip CSS transforms; pointer coordinates include them → mismatch
- Live DOM rects include SortableContext visual swap transforms → oscillation between detected/not-detected

**Source sibling filtering**: Only checks siblings in the source container (via `sourceContainerItemsRef`). This prevents false positives from elements in adjacent containers that share a similar Y position. Cross-container moves are handled by tier 3.

**Pointer-inside-source-sibling guard**: When `centerCrossing` returns empty but the pointer is inside a source sibling rect, return `[]` instead of falling through to tier 3. This prevents SortableContext from receiving a parent container ID as `over` (the parent ID isn't in the items array → `overIndex = -1` → wrong transforms).

## Tier 2: closestCenterLive

**When**: Pending cross-container move active (`hasPendingMoveRef.current === true`).

**Why it exists**: After the pending tree re-renders the DOM, SortableContext applies CSS transforms. `droppableRects` reflect pre-transform positions → collision detection picks wrong elements. Reading live DOM rects via `getBoundingClientRect()` solves this.

**Filtering**: Only pending container siblings (via `pendingContainerItemsRef`). Prevents wrong-container bouncing.

**overRectRef**: NOT captured. At drop time, `handleDragEnd` falls back to `over.rect` (dnd-kit measuring space). This works because `getPointerPosition()` and `over.rect` are both in dnd-kit space. Capturing a live DOM rect here would cause a coordinate space mismatch during auto-scroll (see Coordinate Spaces section).

## Tier 3: Parent Container Fallback

**When**: No sibling collision found in tier 1 or tier 2.

**Why containment-first**: `closestCenter` biases toward smaller containers — a short section's center is closer than a tall section's center, even when the pointer is visually inside the tall section. This was a real bug during cross-section row drops.

**Algorithm**:
1. Find the parent container whose rect contains the pointer position
2. If no containment match (pointer in gap between containers), fall back to `closestCenter`

## Type Filtering

Three filter functions enforce hierarchy-level constraints:

| Function | Returns | Purpose |
|----------|---------|---------|
| `filterDroppablesByType()` | Siblings + parents | All valid targets |
| `filterSiblings()` | Same-type only | Sibling reordering |
| `filterParentContainers()` | Parent-type only | Cross-container targets |

**Root-level special case**: Sections have `parentType === 'root'`. The root SortableContext's droppable ID (`'root'`) doesn't parse as a valid `DraggableType`. `filterParentContainers` matches containers with unparseable IDs when parent type is `'root'`.

## overRectRef Capture

`captureWinnerNode` stores the winning collision's DOM **node reference** (not a rect snapshot). The consumer calls `getBoundingClientRect()` at drop time for a fresh rect.

**Capture rules** (these are deliberate, not accidents):

| Path | Captured? | Why |
|------|-----------|-----|
| Tier 1 hit (centerCrossing) | Yes | No CSS transforms in same-container path — live rect matches pointer space |
| Tier 1 stale-rect recovery | Yes | Same reason — `closestCenterLive` fallback within source container |
| Tier 2 hit (pending path) | **No** | CSS transforms active — live rect is in viewport space, but pointer (from `getPointerPosition`) is in dnd-kit space. Mixing them inverts direction during auto-scroll. |
| Tier 3 (parent containers) | Yes | Parent rects aren't affected by SortableContext child transforms |

## Source Sibling Depletion

When dragging the only item from a container:

```
sourceContainerItemsRef.current.size === 0  (active item excluded)
  ↓
sameContainerSiblings = siblings  (ALL same-type siblings, not filtered to source)
  ↓
centerCrossing can now detect target container siblings directly
  ↓
Precise placement relative to target siblings (instead of appending to container end)
```

**The guard condition**: `sourceItems && sourceItems.size > 0` must be checked before the pointer-inside-source-sibling guard. Without this, the guard fires on the empty set, returning `[]` and blocking the parent-container fallback entirely — the drop silently fails.

## The hadSiblingHit State Machine

```
Drag starts → hadSiblingHit = false
  │
  ├─ centerCrossing finds sibling → hadSiblingHit = true
  │   │
  │   └─ centerCrossing returns empty on next cycle
  │       │
  │       ├─ Pointer inside source sibling? AND hadSiblingHit?
  │       │   → closestCenterLive fallback (maintain over state)
  │       │
  │       └─ Pointer NOT inside source sibling?
  │           → Fall through to tier 3 (parent containers)
  │
  └─ centerCrossing never finds sibling (hadSiblingHit = false)
      │
      └─ Pointer inside source sibling?
          → Return [] (threshold not crossed yet, ghost-jump prevention)
```

**Why this exists**: React re-renders can temporarily make `droppableRects` stale. During that window, `centerCrossing` misses the sibling it was tracking. Without `hadSiblingHit`, the code would either:
- Fall through to tier 3 and snap `over` to a parent container (breaks SortableContext)
- Return `[]` and reset `over` to null (causes `handleDragEnd` to skip the reorder)

## Coordinate Spaces

| Space | Used by | CSS transforms? | Auto-scroll? |
|-------|---------|-----------------|--------------|
| dnd-kit measuring | `droppableRects`, `over.rect`, `active.rect` | Stripped | Via re-measurement |
| Viewport (DOM) | `getBoundingClientRect()`, pointer events | Included | Implicitly |
| dnd-kit sensor | `collisionRect`, `pointerCoordinates` | N/A | Adjusted by dnd-kit |

**The auto-scroll trap**: During auto-scroll, dnd-kit adjusts `collisionRect` and `pointerCoordinates` to account for scroll distance. But `getBoundingClientRect()` shifts in the opposite direction (the element moves within the viewport). Comparing the two gives the wrong sign.

**Column direction detection is a partial exception**: Auto-scroll is typically vertical, so X-axis comparisons between viewport and dnd-kit space are safe. This is why the `overRectRef` skip in tier 2 could theoretically be relaxed for columns — but the current approach (using `over.rect` for all pending-path drops) is simpler and avoids edge cases.
