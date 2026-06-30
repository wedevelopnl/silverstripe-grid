---
description: Important gotchas and caveats to avoid common mistakes
applyTo: "**/*"
---

# Gotchas

## Grid Domain

- **Polymorphic parent ID collisions (PHP)**: page IDs and element IDs share the same numeric space — lookup maps must key by composite `"ParentClass:ParentID"` not just ParentID
- **`GridSettingsOverrides` column stores `null`, not `""` or `"{}"`**: `DBGridSettings::applyGridSettings()` writes `null` when the value object has no overrides and `json_encode(...)` only when at least one viewport differs from the default. Fixture authors and anyone inspecting the column directly need to account for the tri-state read (`null` vs JSON). The value objects implement `JsonSerializable` so serialization is inline at the storage boundary — there is no `serializeOverrides()` utility to reach for.
- **Subclass `onAfterWrite()` must chain `parent::onAfterWrite()`**: auto-scaffolding runs entirely in `GridElement::onAfterWrite()`. `Section`, `Row`, and `Column` ship with no override of their own. A subclass that overrides `onAfterWrite()` without calling `parent::onAfterWrite()` silently skips scaffolding for that class.
- **CMS history viewer needs `GridAwareVersionFormFactory`**: stock `DataObjectVersionFormFactory` strips every `GridField` (which `GridEditorField` subclasses) from the restored form, making the grid silently disappear in the history viewer. `_config/history-viewer.yml` wires our subclass as the Injector alias; any project-level rebinding of `DataObjectVersionFormFactory` must preserve this.
- **DnD coordinate spaces and gotchas**: See the `dnd-guide` skill — covers three coordinate spaces, overRectRef capture rules, auto-scroll traps, and the full diagnostic map for DnD bugs

## SilverStripe 6

- **Extension config access**: Extensions cannot use `static::config()` in SS6 — use `$this->getOwner()->config()->get('key')` instead
- **`ModuleResourceLoader::resolveURL()` throws on missing files**. Always check with `resolveResource()` first:
  ```php
  $resource = $loader->resolveResource($path);
  if (!$resource instanceof ModuleResource || !$resource->exists()) {
      return '';
  }
  return (string) $loader->resolveURL($path);
  ```
- **CMS form holder IDs are form-prefixed**: SilverStripe prefixes holder div IDs with the form name (e.g. `Form_ItemEditForm_FieldName_Holder`). Use suffix selectors like `[id$="_FieldName_Holder"]` to match regardless of prefix
- **Docker volume mounts for vendor module resources**: When adding new `client/` subdirectories that need to be served by the CMS (e.g. `client/images/`), add a corresponding volume mount in `.docker/compose.yml` — the entrypoint creates symlinks via `ln -sfn`

## Frontend / Bridge

- **Entwine `onmatch` does NOT fire for Pjax-loaded content**: jQuery.entwine `onmatch` is unreliable for elements loaded via SilverStripe CMS Pjax navigation, even when the entwine callback registers successfully. Use a vanilla `MutationObserver` instead for detecting elements in AJAX-loaded CMS forms. See `client/src/js/bridge/blockMediaFields.ts` for the working pattern
- **`silverstripe.d.ts` has a top-level `import`** which makes it a module — interfaces like `JQueryEntwineElement`, `JQueryStatic`, `EntwineRules` must live inside the `declare global {}` block to be globally available. Module-scoped interfaces are only accessible within that file or via explicit import

## Tooling

- **CLAUDE.md and AGENTS.md are regenerated from `.apm/instructions/` on every `apm compile`** — direct edits to the generated files get overwritten silently. Edit the APM sources instead.
