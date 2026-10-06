---
description: Element hierarchy rules, auto-scaffolding behavior, and validation logic
applyTo: "**/*"
---

# Element Hierarchy

## Structure

The grid enforces a strict three-level hierarchy using polymorphic parent relationships (ParentID + ParentClass):

```
Page (SiteTree)
  └── Section   [ContainerType::Section]    — canBeRoot() true, zone-scoped
        └── Row   [ContainerType::Row]       — canBeRoot() false
              └── Column  [ContainerType::Column]  — canBeRoot() false, stores GridSettings (DBComposite)
                    └── (any non-container content element)
```

All three container elements implement `ContainerInterface`:
- `getChildren(): HasManyList<GridElement>`
- `hasChildren(): bool`
- `getContainerType(): ContainerType`

Container behavior is shared via `ContainerElementTrait`.

## Hierarchy Rules

Hierarchy rules are hardcoded in `ContainerType` (`canBeRoot()`, `allowedChildClass()`, `isChildAllowed()`) — there is **no** per-class YAML (`allowed_elements`/`disallowed_elements`/`can_be_root`) and no `ElementAllowanceTrait`. Changing what a container accepts means editing `ContainerType`, not config.

- **Section**: allows only `Row` children; can be placed at page root; zone-scoped
- **Row**: allows only `Column` children; cannot be placed at page level
- **Column**: allows any non-container content element (rejects `Section`/`Row`/`Column` via a blocklist); cannot be placed at page level

## Parent Relationships

Elements use a polymorphic `has_one` (`ParentID + ParentClass`) to link to any DataObject:
- Section → parent is `SiteTree` (page)
- Row → parent is `Section`
- Column → parent is `Row`
- Content element → parent is `Column`

Page IDs and element IDs share no namespace separation, so lookup maps must key by composite `"ParentClass:ParentID"` strings.

## Zones

`Zone` (e.g., `"main"`, `"sidebar"`) is declared on `GridElement` and is meaningful ONLY when the element's parent is a `SiteTree` — `GridElementService` forces `''` everywhere else. **Invariant: Zone is non-empty exactly when the parent is a `SiteTree`**, enforced on every write and every stage by `GridElement::validate()` (via `isZoneScoped()`) — NOT by `HierarchyValidationExtension`, which a project may detach. Code writing a page root directly must set a `Zone`; `api/place` refuses a page-root placement without one before localising the block. Older rows breaking it block publish/duplicate/copy-to-locale of their page until `sake tasks:repair-grid-zone` (`Task\OneTime\Beta5\RepairGridZoneTask`) runs; a page's zones are read through `GridZoneResolver`. It lives on the base so one indexed query returns a zone's whole root sequence (Sections and placements together) — never move it back onto a subclass. Sort values are independent per zone per parent. All queries (tree loading, sort assignment, reorder) filter by zone at the root level.

## Auto-Scaffolding

Writing a container element automatically creates its required child structure on DRAFT stage.

### Cascade Chain

Scaffolding lives once in `GridElement::onAfterWrite()`. The child class to create is derived via `ContainerType::allowedChildClass()`:

1. `Section` write → scaffolds a `Row`
2. `Row` write → scaffolds a `Column`
3. `Column` → `allowedChildClass()` returns `null`; no scaffolding (the Column's own `GridSettings` is initialized on first write via a separate hook)

**Result**: A single `Section::create()->write()` produces the full `Section → Row → Column` tree.

### Guard Conditions (Idempotency)

`GridElement::onAfterWrite()` checks before scaffolding:
1. `Versioned::get_stage() === Versioned::DRAFT` — no scaffolding on LIVE
2. `$this->getChildren()->count() > 0` — no scaffolding if children already exist
3. `static::config()->get('auto_scaffold')` — the subclass has not opted out

Auto-scaffolding can be disabled per class via `auto_scaffold: false` in YAML (both `Section` and `Row` default to `true`). Subsequent writes to the same element do NOT create duplicate children.

### Default Titles

`GridElement::ensureDefaultTitle()` runs in `onBeforeWrite()` and **persists** a translatable `"{type} {count}"` title (key `<class>.DEFAULT_TITLE`) whenever `Title` is empty — `count` is the number of same-parent **and same-class** siblings + 1 (the query is `static::get()`, so late static binding scopes it to the element's own class). Scaffolded children are written with an empty `Title` and get their own default from their own write hook, so `"Row 1"` / `"Column 1"` are real column values, not render-time decoration.

`getDisplayTitle()` is the read-time counterpart and a separate mechanism: it falls back to a translatable `(untitled)` for elements that were never written, and for rows predating the write-time default.

## Shared Blocks

`SharedBlock` (library record) owns ONE root subtree via the polymorphic parent; `SharedBlockReference` is the placed element — `Parent`/`Sort`/`Zone` plus `has_one Block`, no content. Rationale for every rule below → `docs/architecture/shared-blocks.md`.

- **Judge a reference by its block's ROOT class**: ask `GridElement::getPlacementClass()`, never `$element::class` (a section-rooted placement would read as a leaf). `SharedBlockReference::getEffectiveRootClass()` pins DRAFT — keep it pinned, or page publish fails on an unpublished block.
- **Placement matrix**: section-rooted → page root; row-rooted → inside a Section; column-rooted → inside a Row; leaf-rooted → inside a Column.
- **No nesting**: a reference never sits inside a shared subtree, nor roots a block. Client suppressions (place, convert) key off `useEditorRoot().kind === 'sharedBlock'`, never per-node fields — library-editor nodes carry no `sharedBlockKey`.
- **No boundary crossing**: `ReorderValidator` fails `SHARED_BOUNDARY`; client collision filtering on `sharedBlockKey` mirrors it.
- **Independent publish**: the reference declares no `$owns` to `Block`. The page owns placements through `GridRoots`.
- **Placements are CREATED only by `SharedBlockController`** (`api/place`, `api/convert`); `RequestBodyParser` and `GridNodeMapper::getAllowedTypes` keep them out of the generic create path. Archive and reorder go through `GridController`.
- `NodeType::SharedBlock` is a tree ROOT type only (the library editor's `rootParent`); a placement's node is `Element`.
- **The block's ROOT is undeletable and unduplicable**: `GridElement::canDelete()` returns false for a `SharedBlock` parent ABOVE `extendedCan`; `GridController::apiDuplicate` refuses it. Client: duplicate actions and the drag handle in `Editable{Section,Row,Column}Block` / `EditableElementCard` gate on `isSharedBlockRootNode()`. Below the root, `canDelete()` delegates to the block's `canEdit()`, never its `canDelete()`.
- **Blocks are created already seeded**: `SharedBlockService::create($rootClass)`, reached only from `GridFieldAddSharedBlockButton` (server-rendered + `client/js/shared-block-add.js`, never the editor bundle). No create endpoint; keep `item/new` closed (`SharedBlockItemRequest::ItemEditForm()` 404s unsaved records). The **Usage** tab is a `GridField` over `SharedBlockUsageResolver::pagesUsing()` with `setFieldFormatting` — never hand-built markup.
- **Never send a `zone` when the parent is a `SharedBlock`**: `parseCreateBody()` rejects `''`; the client's `SharedBlockRoot` (`types/editorRoot.ts`) has no zone field.
- **Fluent**: a placement in a locale implies block content in that locale — `SharedBlockLocaliser::ensureLocalised()`, called from `FluentGridPageExtension` and `SharedBlockService::place()`. The page-copy pass must walk EVERY cloned node (not just roots) and run in the TARGET locale, outside `withState(source)`.
- **Deleting**: the library LISTING removes BOTH `GridFieldArchiveAction` and `GridFieldDeleteAction` (dropping only the archive action promotes a permanent delete). `SharedBlock::canDelete()` never vetoes on usage; `SharedBlockService::delete($block, SharedBlockDeleteMode::Remove|Unshare)` is mandatory-mode. `SharedBlock::onBeforeDelete()` archives every remaining reference on both stages — never delete rows directly.
- **Permissions**: `canEdit`/`canDelete`/`canCreate` and `SharedBlockAdmin::$required_permission_codes` use the literal `'CMS_ACCESS_CMSMain'`. Never bare `CMS_ACCESS` (matches any `CMS_ACCESS_*`), never `'CMS_ACCESS_' . CMSMain::class` (a code nobody holds). `canView` stays on `CMS_ACCESS`.
- **Read-only in the page editor**: `SharedBlockFrame` provides `PlacementContext`; the editable components drop every action inside it. The only actions are `SharedPlacementActions` on the frame bar: open/edit → `sharedBlock.editLink` (the BLOCK's form), remove → archives the PLACEMENT.
- **Convert-to-shared leaves a live window**: the next PAGE publish unlinks the old subtree from live, so the placement renders nothing until the block is published. Pinned by `SharedBlockServiceTest::testConvertLeavesTheOldSubtreeOnLiveUntilThePageIsRepublished`; the `notPublished` badge is the mitigation.

## Hierarchy Validation

Validation happens in two contexts, both delegating to the hardcoded `ContainerType` rules via the shared `PlacementRulesTrait`.

### At Write Time: `HierarchyValidationExtension`

Applied globally to `GridElement` via YAML. Hooks into `updateValidate()` and delegates to `HierarchyValidationService`:

1. Reference anywhere inside a shared context → fail `SHARED_NESTING`
2. Effective class unresolvable (block empty, or no content in this locale) → **pass** — failing here makes consuming pages unpublishable; refusing to PLACE an empty block is `ReorderValidator`'s job
3. No parent → pass (orphan)
4. Parent is a SiteTree page → check `canBeRoot()` on the EFFECTIVE class
5. Parent is a `SharedBlock` → pass (any non-reference class may root a block)
6. Parent is a container → check `isChildAllowed($effectiveClass)` on the parent

Violation throws `ValidationException`, preventing the database write.

### At Reorder Time: `ReorderValidator`

Placement-time only, before the shared rules: a reference to a block with no root fails `BLOCK_EMPTY`.

Called by `ElementPlacementService` (used for both `reorder()` and `insertAfter()` paths, including placement of newly-written elements from `GridElementService`):

1. Same-parent moves short-circuit to ok — hierarchy cannot have changed
2. Source and target shared contexts must match, else fail `SHARED_BOUNDARY`. An unparented (fresh) element has no source context and skips this check
3. Then the shared placement rules above
4. Returns `Result::fail()` for violations (uses Result pattern, not exceptions)

## Integration Test Implications

### Auto-Scaffolding Awareness

Tests creating container elements **must** account for auto-scaffolded children: one `Section` write yields Section → Row → Column (three elements).

### Stage Setup Required

All container integration tests must call `Versioned::set_stage(Versioned::DRAFT)` in `setUp()` because `FlushableTestState::setUp()` clears the reading mode, which would break scaffolding hooks.

## E2E Fixture Ordering

YAML fixtures are written **top-down** (page → section → row → column → leaf) so `=>ClassName.id` parent references resolve. Fixture loading is provided by the `wedevelopnl/silverstripe-e2e` module (dev dependency); the grid declares `config_overrides` on its `FixtureLoader` (in `_config/dev.yml`) forcing `auto_scaffold = false` on `Section` and `Row`, which the module applies via `FixtureBlueprint` callbacks during fixture writes, so parent-first ordering cannot produce duplicate children.

See [docs/testing/e2e-fixtures.md](docs/testing/e2e-fixtures.md) for the full protocol (YAML schema, post-actions, URL segment conventions, the `/dev/e2e-fixtures` HTTP endpoint).
