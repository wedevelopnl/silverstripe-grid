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
- **`$Param!` in `url_handlers` is a PRESENCE check, not validation**: `HTTPRequest::match()` only tests `isset($this->dirParts[$i])`, so `!` guarantees a segment exists — NOT that it is numeric, positive, or non-empty. Never type a route param from the route pattern alone. Validate in the action, then annotate:
  - IDs → `filter_var($request->param('X'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])` → `positive-int`. A plain `(int)` cast turns `abc` into 0 and `12abc` into 12, silently serving page 12 under a bogus URL.
  - Strings → explicit `=== ''` guard → `non-empty-string`.
  - See `GridController::requirePageId()` / `requireZone()`.
- **A trailing `.ext` yields an EMPTY final route segment**: `HTTPRequest::setUrl()` strips the trailing slash BEFORE the extension regex, which then puts one back — `readTree/5/.json` normalises to `readTree/5/` and splits to a trailing `''`. Since `isset('')` is true, `$Zone!` accepts it. Any param that is the LAST segment of its route can therefore arrive as `''`, even with `!`.

## Frontend / Bridge

- **Entwine `onmatch` does NOT fire for Pjax-loaded content**: jQuery.entwine `onmatch` is unreliable for elements loaded via SilverStripe CMS Pjax navigation, even when the entwine callback registers successfully. Use a vanilla `MutationObserver` instead for detecting elements in AJAX-loaded CMS forms. See `client/src/js/bridge/blockMediaFields.ts` for the working pattern
- **`silverstripe.d.ts` has a top-level `import`** which makes it a module — interfaces like `JQueryEntwineElement`, `JQueryStatic`, `EntwineRules` must live inside the `declare global {}` block to be globally available. Module-scoped interfaces are only accessible within that file or via explicit import

## Valibot (API boundary validation)

- **`v.record` accepts arrays as records** (unlike zod's `z.record`, which rejects them): a non-empty array validates as `{ "0": …, "1": … }`. PHP-encoded map fields (`gridSettings.overrides`, `allowedTypes`) must coerce the empty-array sentinel `[]`→`{}` AND reject non-empty arrays — see the `phpMapSchema` helper in `client/src/js/types/schemas.ts`. Fields that are never PHP's empty-map sentinel (e.g. `extensions`) reject ALL arrays with a leading `v.custom` guard (no `[]`→`{}` coercion), matching the original zod behaviour. Verify any "must be an object map" field empirically — `v.record` alone is not strict enough.
- **Recursive discriminated-union schemas need an `as v.GenericSchema<T>` cast at the boundary**: valibot's `~standard` (StandardSchema) output inference cannot resolve the recursive node union and widens map fields to `unknown`, even though `v.InferOutput` resolves correctly and runtime validation is intact. `elementNodeWireSchema` casts the union to `v.GenericSchema<ElementNodeWire>`. This is a type-only escape hatch, not a validation gap.
- **`v.pipe` output inference**: in a coerce-then-validate pipe, the array/type guard (`v.custom`) must come BEFORE the `v.transform`/`v.record`, so the trailing schema determines the pipe's output type. An action placed after the transform pins the output to `unknown`.

## Migration

- **Migration extension hooks fire from extracted collaborators, not `GridMigrationService`**: `updateClassNameMapping` and `updateElementFieldMapping` fire from `DraftHierarchyWriter`; `updateLiveElementFieldMapping` fires from `LivePublisher`; `updateLegacyElements` still fires from `LegacyDataReader` (the facade). Extensions targeting these hooks must be registered on the class that fires the hook, not on `GridMigrationService`.
- **`updateElementFieldMapping` sees DRAFT legacy values only**: for an element shared between both stages, `writeToStage(LIVE)` copies those draft values to live and `LivePublisher::overwriteLiveContent()` corrects only the stock fields. Project fields must be reconciled in `updateLiveElementFieldMapping`, which must write to LIVE only (raw `_Live` UPDATE or `Versioned::withVersionedMode()`) — the record it receives is the draft one.

## Tooling

- **CLAUDE.md and AGENTS.md are regenerated from `.apm/instructions/` on every `apm compile`** — direct edits to the generated files get overwritten silently. Edit the APM sources instead.
- **Vite lib mode inlines every resolvable `url()` as base64 and ignores `assetsInlineLimit`**: `_fonts.scss` therefore points at `../fonts/…`, a path relative to the BUILT stylesheet that deliberately does not resolve from `client/src/styles/`. Vite logs "didn't resolve at build time" per face and emits the URL verbatim; the `ssgrid-copy-fonts` plugin in `vite.config.ts` copies `client/fonts/` → `client/dist/fonts/`. Those six build warnings are the mechanism working. "Fixing" the path re-inlines ~54 KB of base64 into the render-blocking stylesheet and makes `unicode-range` subsetting a no-op.
- **`wedevelopnl/silverstripe-e2e` is versioned in three places — bump them together**: the exact pin in `composer.json` (host vendor) and the exact pins in `.docker/app/composer.json` + `.docker/app/composer.fluent.json` (container vendor). The Playwright client (TS) is imported from the HOST `vendor/`, while the PHP fixture endpoint runs from the CONTAINER vendor — letting them drift skews the client/server contract. Host `composer install`/`update` is required before `npm run typecheck` or the Playwright suite will resolve `@wedevelop/e2e`.
