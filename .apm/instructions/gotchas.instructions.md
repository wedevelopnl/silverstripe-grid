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
- **GridSettings overrides column**: The `GridSettingsOverrides` Text column stores `null` when no overrides exist (not empty string or `{}`). `DBGridSettings::serializeOverrides()` enforces this — any non-null value is valid JSON with at least one viewport key
- **DnD coordinate spaces and gotchas**: See the `dnd-guide` skill — covers three coordinate spaces, overRectRef capture rules, auto-scroll traps, and the full diagnostic map for DnD bugs
