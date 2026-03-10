---
description: Important gotchas and caveats to avoid common mistakes
applyTo: "**/*"
---

# Gotchas

- This is a **ground-up rewrite** for SS6 — do not copy SS5 patterns blindly from `main`/`master` branches
- The SS5 version lives on `main` (and legacy `master`) for architectural reference only
- Active development happens on branch `6` (orphaned from `main`)
- composer.json is intentionally minimal; dependencies will be added incrementally
- `make test-js` and `make coverage-js` run locally (no Docker), unlike PHP targets
- JS linting uses oxlint (`oxlintrc.json`), CSS/SCSS linting uses Stylelint (`stylelint.config.mjs`)
- **E2E tests are opt-in**: `make test-e2e` is NOT part of `make test` or `make qa` — E2E tests require running Docker services and are slow
- **E2E fixtures**: loaded via HTTP (`/dev/grid-fixtures/{load,reset}`), gated to dev environment only
- **E2E TypeScript**: `tests/E2E/` has its own `tsconfig.json` (no vitest globals, includes Playwright types)
- **Polymorphic parent ID collisions**: page IDs and element IDs share the same numeric space — lookup maps must key by composite `"ParentClass:ParentID"` not just ParentID
- **GridSettings sparse storage**: Column GridSettings uses mobile-first cascade — only store viewport overrides, not all 6 viewports. Defaults (`width=12, offset=0, visible=true`) cascade from smallest viewport. PHP's `json_encode([])` emits `[]` not `{}` for empty settings — handle both in frontend/tests
- **DnD pointer position**: dnd-kit's `active.rect.current.translated` drifts from the actual pointer when the grab point isn't at the element center. Use the `getPointerPosition()` helper in `useDragAndDrop.ts` which corrects for grab-point offset
- **DnD stale droppable rects**: After SortableContext applies CSS transforms during a drag, `droppableRects` reflect pre-transform DOM positions. Use `getBoundingClientRect()` (via `closestCenterLive`) for accurate collision detection during pending cross-container moves
- **E2E drag timing**: dnd-kit processes pointer events synchronously but React state updates are batched. Always include delays between drag steps (activation, move, settlement) to let React reconcile. See `tests/E2E/helpers/drag.ts` for calibrated timings
