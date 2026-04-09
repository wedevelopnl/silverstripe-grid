# Drag and Drop Architecture

## Overview

The drag-and-drop system enables visual reordering of elements within the grid editor. It supports both same-container reordering (moving a row within a section) and cross-container moves (moving a row to a different section). The implementation spans a React frontend using dnd-kit and a PHP backend using a layered service architecture.

## System Boundary

```
┌─────────────────────────────────────────────────────────────┐
│ Frontend (React)                                            │
│                                                             │
│  GridEditor                                                 │
│    ├── DnDContext (dnd-kit)                                 │
│    │     ├── Sensors (pointer, 8px activation threshold)    │
│    │     ├── Collision detection (type-aware filtering)      │
│    │     └── Event handlers (start / over / end / cancel)   │
│    │                                                        │
│    ├── SortableContexts (nested, one per container area)    │
│    │     ├── Section level (root area)                      │
│    │     ├── Row level (section's child area)               │
│    │     ├── Column level (row's child area)                │
│    │     └── Element level (column's child area)            │
│    │                                                        │
│    └── Optimistic update pipeline                           │
│          ├── Snapshot current tree                           │
│          ├── Apply reorder locally (applyReorder)           │
│          ├── Update query cache                             │
│          └── Rollback on server error                       │
│                                                             │
├─────────────────────── PATCH /api/reorder ──────────────────┤
│                                                             │
│ Backend (PHP)                                               │
│                                                             │
│  GridController                                             │
│    ├── Request validation (CSRF, payload shape)             │
│    ├── Permission checks (canEdit on element + areas)       │
│    └── Delegates to ReorderService                          │
│                                                             │
│  ReorderService (orchestrator)                              │
│    ├── Phase 1: ReorderValidator (hierarchy rules)          │
│    ├── Phase 2: in-memory sort calculation                  │
│    └── Phase 3: persist via WriteResult (catches exceptions)│
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Data Model

### Element Tree

The API serves a nested tree keyed by area ID:

```
{ [areaId: string]: ElementNode[] }
```

Each `ElementNode` is a discriminated union on `containerType`:

| Type      | containerType | Has children | Has gridSettings |
|-----------|--------------|--------------|------------------|
| Section   | `'section'`  | RowNode[]    | No               |
| Row       | `'row'`      | ColumnNode[] | No               |
| Column    | `'column'`   | SimpleNode[] | Yes              |
| Element   | _(absent)_   | No           | No               |

Every node carries `id` (database primary key) and `parentAreaId` (the area it belongs to). Container nodes additionally carry `childAreaId` (the area that holds their children).

### Reorder Contract

The reorder operation is expressed as three values:

| Field           | Type           | Meaning                                    |
|-----------------|----------------|--------------------------------------------|
| `elementID`     | positive int   | The element being moved                    |
| `targetAreaID`  | positive int   | The area to place it in                    |
| `afterElementID`| positive int / null | Insert after this sibling, or `null` for first position |

This contract is shared between the frontend optimistic update and the backend API endpoint.

## Frontend Architecture

### Lookup Maps

The tree is a nested structure optimized for rendering, not for lookups. Two maps provide O(1) access during drag operations:

- **nodeMap** (`Map<id, ElementNode>`) — find any node by database ID
- **childrenByAreaId** (`Map<areaId, ElementNode[]>`) — find siblings of any node

Maps are built once per tree change via `useMemo`. Every drag hover and drop resolution reads from these maps rather than walking the tree.

### Composite Draggable IDs

dnd-kit identifies draggables and droppables by string ID. The system uses composite IDs in the format `type-numericId` (e.g., `row-42`, `column-17`). This encodes the hierarchy level into the ID, which the collision detection system uses to filter valid drop targets.

### Type-Aware Collision Detection

The active (dragged) item is first excluded from the droppable container list. dnd-kit v6 does not do this automatically — the active item's original-position rect remains registered as a droppable, so `closestCenter` can return it as the nearest target, causing a no-op drop (snap-back).

A two-pass strategy then prevents oscillation between sibling items and their wrapping parent container (which geometrically encloses its children, causing `closestCenter` to oscillate between them):

1. **Pass 1 — Siblings**: Run `centerCrossing` against same-type containers only (e.g. row vs row). This custom strategy detects a collision only when two conditions are met: (a) the collision rect geometrically overlaps the target's bounding rect, and (b) the collision rect center has crossed a direction-aware threshold. The threshold sits at the target's near edge plus half the collision rect height, clamped to the target center. This adapts to DragOverlay measurement asymmetry: when the overlay is similar in size to the target (rows), the threshold equals the target center — preventing ghost jumps. When the overlay is much smaller (sections), the threshold sits near the target edge — remaining reachable by the compact collision rect. The overlap gate ensures that once the collision rect leaves a sibling's area (e.g. when dragging into a different section), that sibling stops being reported as a collision, allowing Pass 2 to fire for cross-container moves. Works on both vertical (Y) and horizontal (X) axes via OR.
2. **Pass 2 — Parent containers**: If no sibling collision exists (e.g. dragging into an empty container), fall back to `closestCenter` against parent-type containers only.

Drop target validity follows hierarchy rules:

- A **section** can only drop on other sections or the root area
- A **row** can only drop on other rows or into a section
- A **column** can only drop on other columns or into a row
- An **element** can only drop on other elements or into a column

This prevents the user from seeing invalid drop indicators and ensures sibling reordering always takes priority over container drops. The filtering happens entirely on the client; the backend validates independently.

### Nested SortableContexts

Each container's children live in their own `SortableContext` with that container's composite child IDs. This creates a hierarchy of sortable regions:

```
SortableContext (root area → section IDs)
  └── SortableContext (section's child area → row IDs)
        └── SortableContext (row's child area → column IDs)
              └── SortableContext (column's child area → element IDs)
```

On drop, the system determines the target container by comparing the active item's type against the over item's type. Same type means sibling reorder (use parent area). Different type means cross-container move (use container's child area).

### Cross-Container Visual Feedback

During drag, `handleDragOver` provides real-time visual feedback for cross-container moves. When the collision detection returns a target in a different container, `handleDragOver` applies a temporary reorder to the tree via `applyReorder()`, updating the enriched sections so the dragged item visually appears in the target container. This pending tree is stored in component state and cleared on drop or cancel. Same-container reordering uses dnd-kit's built-in CSS transform approach (no tree mutation needed).

### Optimistic Update Pipeline

```
User drops element
  │
  ├─ resolveReorderParams()
  │    ├─ No-op? (same area + same index) → abort, no mutation
  │    └─ Compute { elementID, targetAreaID, afterElementID }
  │
  ├─ Mutation fires (TanStack Query)
  │    ├─ onMutate: snapshot cache, apply applyReorder(), update cache
  │    ├─ onError: restore snapshot (rollback)
  │    └─ onSettled: invalidate query (server reconciliation)
  │
  └─ applyReorder() (pure function)
       ├─ No-op detection → return same tree reference
       ├─ structuredClone() the tree
       ├─ Splice element from source children array
       ├─ Update parentAreaId if cross-area
       ├─ Insert into target children array
       └─ Preserve references for unaffected root areas
```

The no-op detection and reference preservation are deliberate: React skips re-rendering subtrees whose root reference hasn't changed.

### Enrichment Layer

Raw tree nodes lack UI state. An enrichment pass adds:

- **sortableId** — the composite ID for dnd-kit registration
- **childSortableIds** — ordered child IDs for the nested `SortableContext`
- **isCollapsed / toggle** — per-node collapse state persisted to localStorage

Enrichment runs once per tree change or collapse state change. It does not run during drag (only on drop, when the tree updates).

### Re-render Characteristics

A drop replaces the cached tree, which causes the full component tree to re-render. This is a conscious tradeoff:

- The tree is immutable — `structuredClone` produces new references for affected subtrees
- All block components receive new enriched props
- No `React.memo` boundaries exist between GridEditor and leaf components

For the expected scale (dozens of sections, not hundreds), this produces no measurable latency. The architecture supports adding `React.memo` boundaries later if scale demands it, without structural changes.

## Backend Architecture

### Service Design

```
Controller (HTTP concerns)
  └── ReorderService (orchestration — validation, sort calculation, persistence)
        ├── ReorderValidatorInterface (hierarchy rules — injected)
        └── GridElementRepositoryInterface (sibling loads — injected)
```

`ReorderService` owns all three phases directly. Validation is delegated to the injected `ReorderValidatorInterface`; sort calculation happens inline via private helpers (`filterByZone`, `excludeElement`, `resolveInsertionIndex`, `reindex`); persistence goes through `persistAndReturn()`, which wraps the writes in `WriteResult::from()` to translate any thrown `ValidationException` into `Result::fail()`. The service communicates with callers exclusively via the `Result` pattern.

### Phase 1: Validation

**Same-area moves** skip validation entirely — reordering within a container cannot violate hierarchy rules.

**Cross-area moves** check two things:
1. **can_be_root** — if the target area belongs to a page, the element must be allowed at root level
2. **allowed_elements / disallowed_elements** — the target container must accept this element type

Validation returns `Result::fail()` with structured errors on violation. No database writes or in-memory mutations occur.

### Phase 2: Sort Calculation

All sort work happens in memory, inside `ReorderService::reorder()`:

1. Load siblings of the target area via the repository (sorted by `Sort ASC, ID ASC`)
2. For `Section` moves, filter the siblings to the element's `Zone` (`filterByZone()`) — sort values are per-zone-per-parent, so mixing zones would corrupt the index
3. Exclude the moved element from the sibling list
4. Resolve the insertion index from `afterElementID`
5. `array_splice()` the element into position
6. Reindex sort values (1-based: 1, 2, 3, ...) via `reindex()`
7. Track dirty elements (only those whose `Sort` or `ParentID` actually changed)

For cross-area moves, the source area's siblings are loaded and reindexed the same way to close the gap.

### Phase 3: Persistence

Only dirty elements are written. `persistAndReturn()` calls `WriteResult::from()` with a closure that writes each dirty element; `WriteResult` catches any `ValidationException` thrown during the writes and converts it to a failed `Result`, maintaining the Result-pattern contract through the entire stack.

### Result Pattern

All service-layer operations return `Result<T>` instead of throwing exceptions for expected failures:

```
Result::ok($value)    — success, carries the value
Result::fail($errors) — failure, carries ValidationError[]
```

The controller maps `Result::ok()` to HTTP 204 and `Result::fail()` to HTTP 422 with structured error JSON.

### Permission Model

The controller checks permissions before delegating to the service layer:

- CSRF token validation (SecurityToken)
- `canEdit()` on the element being moved
- `canEdit()` on the target area
- `canEdit()` on the source area (cross-area moves only)

The service layer assumes permissions have been checked and focuses purely on domain logic.

## Consistency Model

The system uses **optimistic concurrency** without explicit locking:

1. Frontend applies the reorder immediately to the local cache
2. Backend processes the request against current database state
3. Frontend reconciles by invalidating the query after the server responds

If the server state has diverged (another user reordered simultaneously), the invalidation fetches the authoritative tree and overwrites the optimistic state. There is no conflict resolution — last write wins at the database level.

## Error Recovery

| Failure Point              | Recovery                                             |
|---------------------------|------------------------------------------------------|
| Network error             | Rollback to pre-mutation snapshot, query stays stale |
| Server validation failure | Rollback to snapshot, display error                  |
| Server persistence error  | Rollback to snapshot, display error                  |
| Stale optimistic state    | `onSettled` invalidation fetches authoritative tree  |
