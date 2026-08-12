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
│    ├── Request validation (CSRF, NodeRef payload shape)     │
│    ├── Permission checks (canEdit on element + parents)     │
│    └── Delegates to ElementPlacementService                 │
│                                                             │
│  ElementPlacementService (orchestrator)                     │
│    ├── Phase 1: ReorderValidator (hierarchy rules)          │
│    ├── Phase 2: in-memory sort calculation                  │
│    └── Phase 3: persist via WriteResult inside a tx         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Data Model

### Node Identity

Every node carries a scoped identity shaped as `NodeRef = { type: NodeType, id: number }` where `NodeType` is one of `page | section | row | column | element`. A page's ID and an element's ID can collide — they come from independent auto-increment sequences — so every map, every dnd-kit draggable, every API payload pairs the numeric id with its type.

The flat-string form is `NodeKey = '${type}-${id}'` (e.g. `row-42`, `column-17`). Helpers live in `client/src/js/types/identity.ts` under the `NodeIdentity` namespace: `toKey`, `fromKey`, `equals`. Every composite ID dnd-kit sees is a `NodeKey`.

### Element Tree

The API returns a flat list of root-level nodes plus an explicit root parent identity:

```ts
interface TreeApiResponse {
  rootParent: NodeRef;           // always { type: 'page', id: <pageId> } for the current API
  nodes: ElementNode[];          // root sections for the zone
}
```

Each `ElementNode` is a discriminated union on `containerType`:

| Type      | containerType | Has children | Has gridSettings |
|-----------|--------------|--------------|------------------|
| Section   | `'section'`  | `RowNode[] \| null`    | No |
| Row       | `'row'`      | `ColumnNode[] \| null` | No |
| Column    | `'column'`   | `SimpleElementNode[] \| null` | Yes |
| Element   | _(absent)_   | No                     | No |

Every node carries:

- `self: NodeRef` — the canonical identity of this node
- `parent: NodeRef` — the identity of its parent
- `nodeKey: NodeKey` / `parentKey: NodeKey` — precomputed string forms for Map/Set keys and dnd-kit IDs
- `id: number` — bare numeric ID, safe only for the GridElement-only endpoints (publish, unpublish, delete, duplicate, updateGridSettings) that query `GridElement::get()` exclusively. Never use as a map key.

Container nodes additionally carry `containerType`, `allowedTypes`, and `children`. Column nodes carry `gridSettings`.

### Reorder Contract

The reorder payload is three `NodeRef`s:

| Field      | Type              | Meaning |
|------------|-------------------|---------|
| `element`  | `NodeRef`         | The element being moved |
| `parent`   | `NodeRef`         | The target parent to place it in |
| `after`    | `NodeRef \| null` | Insert after this sibling (must be the same `type` as `element`), or `null` for first position |

This contract is shared between the frontend optimistic update and the backend API endpoint (see `src/Value/ReorderRequest.php`). The parser additionally rejects `element.type === 'page'` and requires `after.type === element.type`.

## Frontend Architecture

### Lookup Maps

The tree is a nested structure optimized for rendering, not for lookups. Three maps provide O(1) access during drag operations (see `client/src/js/hooks/useElementMaps.ts`):

- **nodeMap** (`Map<NodeKey, ElementNode>`) — find any node by its composite key (pages are not stored)
- **childrenByParentKey** (`Map<NodeKey, ElementNode[]>`) — find siblings of any node (the root entry is keyed by the page's `NodeKey`)
- **indexByNodeKey** (`Map<NodeKey, number>`) — position of each node within its parent's children array; the canonical O(1) sibling-index lookup used by reorder, drop-placement, and collision code on every drag-over frame instead of an O(n) `findIndex` scan

Maps are built once per tree change via `useMemo`. Every drag hover and drop resolution reads from these maps rather than walking the tree.

### Composite Draggable IDs

dnd-kit identifies draggables and droppables by string ID. The system uses `NodeKey` strings directly (`section-12`, `row-42`, `column-17`, `element-108`). This encodes the hierarchy level into the ID, which the collision detection system uses to filter valid drop targets. Helper `NodeIdentity.toKey(ref)` (or the `nodeKey` field already on every node) produces the canonical form.

### Type-Aware Collision Detection

The active (dragged) item is first excluded from the droppable container list. dnd-kit v6 does not do this automatically — the active item's original-position rect remains registered as a droppable, so `closestCenter` can return it as the nearest target, causing a no-op drop (snap-back).

Beyond that, collision detection branches on whether a pending cross-container move is in flight. The full algorithm lives in `client/src/js/utils/collisionDetection.ts`; the working model is:

1. **Pass 1 — Siblings** (when no pending move): run `centerCrossing` against same-type containers only (row vs row). This custom strategy detects a collision only when (a) the collision rect geometrically overlaps the target's bounding rect, *and* (b) the collision rect center has crossed a direction-aware threshold. The threshold sits at the target's near edge plus half the collision rect height, clamped to the target center. This adapts to DragOverlay measurement asymmetry: overlays similar in size to the target (rows) → threshold at target center (prevents ghost jumps); much smaller overlays (sections) → threshold near the target edge (stays reachable). The overlap gate lets Pass 2 take over when the collision rect leaves sibling territory. Works on both vertical (Y) and horizontal (X) axes via OR.
2. **Pass 1′ — Sibling fallback for pending moves**: when a cross-container move has already been staged in the pending tree, sibling matching switches to `closestCenterLive` which reads live `getBoundingClientRect` values rather than dnd-kit's cached `droppableRects`. This bypasses the stale rects produced by the optimistic tree reshuffle.
3. **Pass 2 — Parent containers**: if no sibling collision exists (e.g. dragging into an empty container), fall back to `closestCenter` against parent-type containers only.

A pointer-overlap gate (axis-dependent margins: X = 50, Y = 150) and a `pointerCoordinates` fallback handle auto-scroll drift where `collisionRect` stops representing the visual drop target. Source-container filtering (`sourceContainerItemsRef`) excludes the drag's origin from being reported as its own target.

Drop target validity follows hierarchy rules:

- A **section** can only drop on other sections or the root area
- A **row** can only drop on other rows or into a section
- A **column** can only drop on other columns or into a row
- An **element** can only drop on other elements or into a column

This prevents the user from seeing invalid drop indicators and ensures sibling reordering always takes priority over container drops. The filtering happens entirely on the client; the backend validates independently.

### Nested SortableContexts

Each container's children live in their own `SortableContext` registered under the container's `nodeKey`. This creates a hierarchy of sortable regions:

```
SortableContext (page root → section NodeKeys)       [vertical]
  └── SortableContext (section.nodeKey → row NodeKeys)   [vertical]
        └── SortableContext (row.nodeKey → column NodeKeys)   [horizontal]
              └── SortableContext (column.nodeKey → element NodeKeys)  [vertical]
```

Rows use `horizontalListSortingStrategy`; all other levels use the default vertical strategy.

On drop, the system determines the target container by comparing the active item's `NodeType` against the over item's `NodeType`. Same type means sibling reorder (use the parent's `NodeKey`). Different type means cross-container move (use the container's own `NodeKey`).

### Cross-Container Visual Feedback

During drag, `handleDragOver` provides real-time visual feedback for cross-container moves. When collision detection returns a target in a different container, `handleDragOver` applies a temporary reorder via `applyReorder()`, producing an updated tree that `usePendingTree` exposes as the effective tree via `getEffective()`. The pending tree is cleared on drop or cancel. Same-container reordering uses dnd-kit's built-in CSS transform approach (no tree mutation needed).

`usePendingTree` also tracks auxiliary refs (`hasPendingMoveRef`, `pendingContainerItemsRef`, `sourceContainerItemsRef`, `overRectRef`) that collision detection uses to keep coordinate resolution stable while the tree is mid-mutation.

### Optimistic Update Pipeline

```
User drops element
  │
  ├─ resolveReorderParams()
  │    ├─ No-op? (same parent + same index) → abort, no mutation
  │    └─ Compute { element: NodeRef, parent: NodeRef, after: NodeRef | null }
  │
  ├─ Mutation fires (TanStack Query)
  │    ├─ onMutate: snapshot cache, apply applyReorder(), update cache
  │    ├─ onError: restore snapshot (rollback)
  │    └─ onSettled: invalidate query (server reconciliation)
  │
  └─ applyReorder(tree, elementKey, parentKey, afterKey)  — pure function
       ├─ No-op detection → return same tree reference
       ├─ structuredClone(tree.nodes)
       ├─ Splice element from source children array
       ├─ Update element.parent + element.parentKey if cross-parent
       ├─ Insert into target children array
       └─ Preserve rootParent reference
```

The no-op detection and reference preservation are deliberate: React skips re-rendering subtrees whose root reference hasn't changed.

### Node Shape

Raw tree nodes already arrive with every piece of data the UI needs — there is no separate "enrichment" pass:

- **`nodeKey` / `parentKey`** — precomputed composite keys, used both for Map lookups and directly as dnd-kit draggable/droppable IDs. Populated at the fetch boundary by the API layer's `normaliseTreeResponse`, which maps each node through `attachDerivedFields`.
- **`self` / `parent`** — structural `NodeRef`s for code that prefers the typed form.
- **children / gridSettings** — populated per container type.
- **allowedTypes** — not on the wire per node. The response carries one map per container type at the tree root, and `attachDerivedFields` re-attaches it to each container node **by reference**, so every section shares one array object (likewise rows and columns). Treat it as immutable: mutating it mutates every node's.

Per-node collapse state (`isCollapsed`, toggle) lives in a separate `CollapseContext` (`client/src/js/hooks/useCollapseState.ts`), consumed via the `useCollapse()` hook. It is persisted to `localStorage` and is decoupled from the tree shape so toggling a collapse state does not invalidate tree memos.

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
  └── ElementPlacementService (orchestration — validation, sort calc, persistence)
        ├── ReorderValidatorInterface (hierarchy rules — injected)
        └── GridElementRepositoryInterface (sibling loads — injected)
```

`ElementPlacementService` owns all three phases directly. It has two entry points with identical pipelines: `reorder()` for already-placed elements and `insertAfter()` for newly-written ones (used by `GridElementService` after creating or duplicating an element). Validation delegates to the injected `ReorderValidatorInterface`; sort calculation happens inline via private helpers (`filterByZone`, `excludeElement`, `resolveInsertionIndex`, `reindex`); persistence goes through `persistAndReturn()`, which wraps the writes in a `DB::get_conn()->withTransaction()` and `WriteResult::from()` to translate any thrown `ValidationException` into `Result::fail()`. The service communicates with callers exclusively via the `Result` pattern.

### Phase 1: Validation

`ReorderValidator::validate()` short-circuits to `Result::ok()` when the element's current parent (`ParentID` + `ParentClass`) already equals the target parent — a same-parent reorder cannot change the hierarchy, so no rule check runs.

Cross-parent moves delegate to the target parent's `ContainerType` and check two things:
1. **`canBeRoot()`** — if the target parent is a page (`SiteTree`), the element's container type must be allowed at root level (only `Section` is)
2. **`isChildAllowed($element::class)`** — the target container must accept this element type (Section→Row, Row→Column, Column→any non-container element)

Validation returns `Result::fail()` with structured errors on violation. No database writes or in-memory mutations occur.

### Phase 2: Sort Calculation

All sort work happens in memory, inside `ElementPlacementService::reorder()`:

1. Load siblings of the target parent via the repository (sorted by `Sort ASC, ID ASC`)
2. For `Section` moves, filter the siblings to the element's `Zone` (`filterByZone()`) — sort values are per-zone-per-parent, so mixing zones would corrupt the index
3. Exclude the moved element from the sibling list
4. Resolve the insertion index from the `after` ref's `id` (null → insert first; id-not-found → `Result::fail`)
5. `array_splice()` the element into position
6. Reindex sort values (1-based: 1, 2, 3, ...) via `reindex()`
7. Track dirty elements (only those whose `Sort` or `ParentID` actually changed)

For cross-parent moves, the source parent's siblings are loaded and reindexed the same way to close the gap.

### Phase 3: Persistence

Only dirty elements are written. `persistAndReturn()` wraps the loop in a DB transaction so a mid-loop failure rolls the whole batch back, then `WriteResult::from()` catches any `ValidationException` and converts it to a failed `Result`, maintaining the Result-pattern contract through the entire stack.

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
