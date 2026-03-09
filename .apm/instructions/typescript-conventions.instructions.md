---
description: TypeScript and React frontend conventions
applyTo: "**/*.{ts,tsx}"
---

# TypeScript & React Conventions

## Stack

- React 18, TypeScript 5.9, Vite 7, SCSS
- dnd-kit for drag & drop
- TanStack Query for data fetching
- Zod for runtime validation and schema definitions

## Structure

- `client/src/` is the frontend root
- `@` path alias maps to `client/src` (configured in `vite.config.ts` and `tsconfig.json`)
- Entry points in `client/src/bundles/`
- SilverStripe CMS integration via entwine and Injector in `client/src/bridge/`

## Testing

- Vitest + React Testing Library with jsdom environment
- Test files in `client/src/tests/`
- Stryker for mutation testing

## Key Patterns

- **Zod-first types**: Schemas defined first in `client/src/types/`, TS types inferred via `z.infer<>`. Discriminated unions for element nodes. Type guards for narrowing.
- **Query key factory**: `client/src/hooks/queryKeys.ts` provides factories for TanStack Query cache keys. Required for correct cache invalidation across mutations.
- **API client layers**: 4-file architecture in `client/src/api/` — `client.ts` (HTTP primitives), `endpoints.ts` (business operations), `config.ts` (CMS globals like security token, base URL), `errors.ts` (typed error classes).
- **Bridge pattern**: entwine in `client/src/bridge/` mounts React components into jQuery DOM. Injector wraps SilverStripe DI. New components registered via `client/src/boot/registerComponents.ts`.

## Drag & Drop (dnd-kit)

Detailed architecture documented in `docs/architecture/drag-and-drop.md`.

### Composite IDs

Draggable/droppable IDs encode hierarchy level: `type-numericId` (e.g., `row-42`, `column-7`). Parse with `parseDraggableId()`, build with `buildDraggableId()`. `PARENT_CONTAINER_TYPE` maps each type to its parent (`element→column→row→section→root`).

### Collision Detection (3-tier)

`createTypedCollisionDetection()` in `client/src/utils/collisionDetection.ts`:

1. **centerCrossing** (siblings, no pending move) — Direction-aware threshold crossing with overlap gate. Prevents ghost jumps by requiring the collision rect center to actually cross a threshold on the target, not just be nearest. Threshold adapts to DragOverlay size asymmetry (compact overlay ≈53px vs full element ≈350px).
2. **closestCenterLive** (siblings, pending move active) — Reads live DOM rects via `getBoundingClientRect()` because CSS transforms from SortableContext make `droppableRects` stale. Filtered to pending container siblings only.
3. **closestCenter** (parent containers) — Distance-based fallback for cross-container entry.

### Pending Tree Pattern

During cross-container drags, `handleDragOver` calls `applyReorder()` to produce a mutated tree stored in `pendingTree` state. This provides immediate visual feedback (element appears in target container) without triggering API mutations. `pendingContainerItemsRef` tracks valid sibling IDs for collision filtering. Cleared on drop or cancel.

### Direction-Aware Placement

`handleDragOver` and `handleDragEnd` compare pointer position against the `over` element's center to determine before/after placement. Columns use X-axis (horizontal layout), all other levels use Y-axis (vertical layout).

### Key Files

| File | Purpose |
|------|---------|
| `client/src/hooks/useDragAndDrop.ts` | Core hook: sensors, callbacks, pending tree state |
| `client/src/utils/collisionDetection.ts` | 3-tier collision detection, type filtering |
| `client/src/utils/applyReorder.ts` | Immutable tree mutation for optimistic updates |
| `client/src/utils/resolveReorderParams.ts` | Maps dnd-kit event context to API payload |
| `client/src/hooks/useElementMaps.ts` | O(1) lookup maps: `nodeMap`, `childrenByParentId` |
| `client/src/types/dnd.ts` | Composite IDs, type constants, parent-type mapping |

### GridSettings (Sparse Storage)

Column grid settings use mobile-first cascade. Only viewport overrides are stored — defaults (`width=12, offset=0, visible=true`) cascade from the smallest viewport. `Column::getColumnClasses()` walks viewports smallest→largest, emitting CSS classes only when the effective value changes from the previous breakpoint.
