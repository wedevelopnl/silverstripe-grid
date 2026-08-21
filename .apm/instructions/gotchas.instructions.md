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
- **Convert-to-shared leaves a live window**: converting is draft-only, so live keeps rendering the old subtree — but the NEXT page publish runs SilverStripe's disowned-object cleanup, clearing that subtree's live parent link. Between then and publishing the block, the placement renders nothing on live. Pinned by `SharedBlockServiceTest::testConvertLeavesTheOldSubtreeOnLiveUntilThePageIsRepublished` and `testRepublishingThePageUnlinksTheDisownedSubtreeFromLive`; the editor's `notPublished` badge is the mitigation.
- **`$Sections` never includes shared blocks**: it is a Section-only relation. Templates must use `$GridZone('<zone>')`, which merges Sections and `SharedBlockReference`s by Sort across two tables. A page rendered through `$Sections` silently omits every placement.
- **Anything that enumerates a page's root elements must cover BOTH root classes** — the set is named once as `GridElement::ROOT_ELEMENT_CLASSES`; read it through `OrmGridElementRepository::findByParents($idsByClass, $zone)` rather than re-querying. This has already bitten Fluent copy-to-locale (placements never copied), locale deletion (placements stranded) and `ElementPlacementService` (placements dropped from the root sibling list, so a move anchored on one 404'd and a reindex collided with its Sort). `Section::get()` at page root is nearly always a bug.
- **Every container's child has_many is typed to `GridElement`, never to the concrete child class** — `Section.Rows`, `Row.Columns` and `Column.Elements` all point at `GridElement.Parent`. A shared block placed mid-tree is a `SharedBlockReference` standing in for a Row or Column, so a class-narrowed relation drops it from `$owns`, `$cascade_deletes`, `$cascade_duplicates`, `getChildren()` and the `$Rows`/`$Columns` template loops — the placement shows in the editor and never publishes or renders. Narrowing one back is always a bug; add nothing beside it either (one relation per owner, see below).
- **The auto-scaffold guard counts `GridElement::get()`, not `$childClass::get()`** — a placement standing in for the Row/Column is a child. Counting per-class made a container holding only a placement scaffold a phantom empty child on its next write.
- **Ownership goes through ONE has_many, `GridRoots` (`GridElement.Parent`)**: two has_many relations on the same owner pointing at the same polymorphic `.Parent` make SilverStripe's reverse owner lookup ambiguous and silently break the page's publish cascade — sections stop reaching live with no error. Listing both in `$cascade_duplicates` would also copy every Section twice. `Sections` stays declared for `$Sections` in templates but is deliberately NOT in `$owns`/`$cascade_*`.
- **A placement carries children without being a container**: `isContainerNode()` is false for it, so any tree walk gated on that alone skips its whole subtree — this is what broke `buildMaps` (frontend). Check `isSharedBlockReferenceNode()` too.
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
  - See `GridApiController::requireIdParam()` / `GridController::requireZone()`.
- **A trailing `.ext` yields an EMPTY final route segment**: `HTTPRequest::setUrl()` strips the trailing slash BEFORE the extension regex, which then puts one back — `readTree/5/.json` normalises to `readTree/5/` and splits to a trailing `''`. Since `isset('')` is true, `$Zone!` accepts it. Any param that is the LAST segment of its route can therefore arrive as `''`, even with `!`.

## Frontend / Bridge

- **Entwine `onmatch` does NOT fire for Pjax-loaded content**: jQuery.entwine `onmatch` is unreliable for elements loaded via SilverStripe CMS Pjax navigation, even when the entwine callback registers successfully. Use a vanilla `MutationObserver` instead for detecting elements in AJAX-loaded CMS forms. See `client/src/js/bridge/blockMediaFields.ts` for the working pattern
- **JSON in a `.ss`-rendered attribute loses its backslash escapes**: `$Var.ATT` reaches the browser with `\\` resolved to `\`, so a `json_encode`d map keyed by class names (`WeDevelop\\Grid\\Model\\X`) arrives as invalid JSON and parses to nothing. `Convert::raw2att()` is not the culprit — it preserves them; the template layer does it. Base64-encode the payload and decode it in the bridge (`Uint8Array.from(atob(raw), c => c.charCodeAt(0))` through `TextDecoder`, since labels are translated and may be non-ASCII). See `GridFieldAddSharedBlockButton` + `bridge/addSharedBlockButton.ts`.
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
- **Biome's `noPlaywrightMissingAwait` misfires on jest-dom's `toBeDisabled()` in some Vitest files** (it mistakes the matcher for Playwright's async one; renaming variables and receivers does not appease it). Assert via `expect(el).toHaveProperty('disabled', true)` instead — see `SharedBlockHosting.test.tsx` for the precedent.
- **Vite lib mode inlines every resolvable `url()` as base64 and ignores `assetsInlineLimit`**: `fonts.css` therefore points at `../fonts/…`, a path relative to the BUILT stylesheet that deliberately does not resolve from `client/src/styles/`. Vite logs "didn't resolve at build time" per face and emits the URL verbatim; the `ssgrid-copy-fonts` plugin in `vite.config.ts` copies `client/fonts/` → `client/dist/fonts/`. Those six build warnings are the mechanism working. "Fixing" the path re-inlines ~54 KB of base64 into the render-blocking stylesheet and makes `unicode-range` subsetting a no-op.
- **Never run a build while the Playwright suite is running**: `npm run build`, `npm run dev` and `task qa` (which ends in `vite build`) rewrite `client/dist`, which the container serves through a volume mount — so the bundle changes underneath a browser mid-run. The symptom is scattered failures in unrelated specs, mostly DnD ones, that pass in isolation and look like flakes. Let the suite finish first. A dead app container reads differently: `connect ECONNREFUSED` on the fixture endpoint, or a `docker compose ps` with no `app` row (check `docker inspect … --format '{{.State.ExitCode}}'`; 139 is a segfault). Restart with `docker compose -f .docker/compose.yml up -d app` and re-run — those failures say nothing about the code.
- **`wedevelopnl/silverstripe-e2e` is versioned in three places — bump them together**: the exact pin in `composer.json` (host vendor) and the exact pins in `.docker/app/composer.json` + `.docker/app/composer.fluent.json` (container vendor). The Playwright client (TS) is imported from the HOST `vendor/`, while the PHP fixture endpoint runs from the CONTAINER vendor — letting them drift skews the client/server contract. Host `composer install`/`update` is required before `npm run typecheck` or the Playwright suite will resolve `@wedevelop/e2e`.
