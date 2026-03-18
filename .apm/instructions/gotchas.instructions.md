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
- **Polymorphic parent ID collisions**: page IDs and element IDs share the same numeric space — lookup maps must key by composite `"ParentClass:ParentID"` not just ParentID
- **GridSettings sparse storage**: Column GridSettings uses mobile-first cascade — only store viewport overrides, not all 6 viewports. Defaults (`width=12, offset=0, visible=true`) cascade from smallest viewport. PHP's `json_encode([])` emits `[]` not `{}` for empty settings — handle both in frontend/tests
- **DnD pointer position**: dnd-kit's `active.rect.current.translated` drifts from the actual pointer when the grab point isn't at the element center. Use the `getPointerPosition()` helper in `useDragAndDrop.ts` which corrects for grab-point offset
- **DnD stale droppable rects**: After SortableContext applies CSS transforms during a drag, `droppableRects` reflect pre-transform DOM positions. Use `getBoundingClientRect()` (via `closestCenterLive`) for accurate collision detection during pending cross-container moves. However, do NOT use `getBoundingClientRect()` for drop-time direction detection during pending moves — `getPointerPosition()` uses dnd-kit's coordinate system (includes auto-scroll adjustments), while `getBoundingClientRect()` returns viewport-relative coordinates that shift oppositely during auto-scroll. Use `over.rect` (pre-transform, from dnd-kit) for direction comparison instead
