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

`Zone` (e.g., `"main"`, `"sidebar"`) is declared on `GridElement` and is meaningful ONLY when the element's parent is a `SiteTree` — `GridElementService` forces `''` everywhere else. Declaring it on the base is what lets one indexed query return a zone's whole root sequence (Sections and placements together); when it lived on the root subclasses, every root read and every root Sort assignment fanned out to one query per class. Sort values are independent per zone per parent. All queries (tree loading, sort assignment, reorder) filter by zone at the root level.

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

A `SharedBlock` is a library record owning ONE subtree via the same polymorphic parent (`ParentClass = SharedBlock::class`). A `SharedBlockReference` is the placed element; it holds only placement data (`Parent`, `Sort`, `Zone` — all inherited from `GridElement`) and a `has_one Block`.

- **Effective class**: placement rules judge a reference by its block's ROOT class, never by `SharedBlockReference`. Ask `GridElement::getPlacementClass()` — it answers `static::class` for an ordinary element and the block's root class for a placement, so no caller needs an `instanceof`. A caller that reads `$element::class` directly treats a section-rooted placement as a leaf. `SharedBlockReference` implements it via `getEffectiveRootClass()`, which pins the DRAFT stage — a block's shape is structural, and resolving on the ambient stage makes page publish fail whenever the block is unpublished.
- **Placement matrix**: section-rooted → page root; row-rooted → inside a Section; column-rooted → inside a Row; leaf-rooted → inside a Column. Same rules as the class it stands in for.
- **No nesting**: a reference may never sit anywhere inside a shared subtree, nor root a block. This is what removes cycle detection entirely.
- **No boundary crossing**: `ReorderValidator` rejects any move whose source and target sit on different sides of a shared boundary. Client-side collision filtering mirrors it; the validator is the backstop.
- **Independent publish**: the reference declares no `$owns` to `Block`, so page publish stops at the boundary. `GridPageExtension` owns the single `GridRoots` relation (`GridElement.Parent`), which covers both root classes — a placement is page content and must publish, archive and duplicate with its page.
- **Excluded from the generic create path**: `RequestBodyParser` rejects it and `GridNodeMapper::getAllowedTypes` filters it out. Placements are CREATED only by `SharedBlockController` (`api/place`, `api/convert`), which carry the `blockId`; once created they are ordinary elements, archived and reordered through `GridController`.
- `NodeType::SharedBlock` is a tree ROOT type only (the library editor's `rootParent`). A placement's own node is typed `Element`.
- `GridElement::canDelete()` delegates to the owning record's `canEdit()` for elements inside a block, never its `canDelete()` — a project extension vetoing block deletion must not freeze the block's contents.
- **The block's ROOT is undeletable and undupliable.** `GridElement::canDelete()` returns false when the parent is a `SharedBlock`, checked ABOVE `extendedCan` — structural invariant, not a permission, so no `updateCanDelete` can grant it. It reaches the UI through the tree payload's `canDelete` (`useArchiveAction` returns no action) and the API through `apiDelete`. `GridController::apiDuplicate` refuses the root for the mirror reason: duplicating in place copies `ParentID`/`ParentClass`, giving the block a second root that `getRootElement()`'s `->first()` then hides. Client-side both duplicate actions gate on `isSharedBlockRootNode()`, as does the drag handle in all four editable components (`Editable{Section,Row,Column}Block`, `EditableElementCard`) — the root is alone at its level, so a drag from it has no legal target. Framework removal paths check no permission (`DataObject::delete()`, `doArchive()`, `$cascade_deletes`), so deleting the BLOCK still takes its root with it.
- **Blocks are created already seeded.** `SharedBlockService::create(class-string<GridElement> $rootClass)` writes block + root in one transaction; the root's own write scaffolds the rest. Reached from `GridFieldAddSharedBlockButton`, which `SharedBlockAdmin::getGridFieldConfig()` swaps in for `GridFieldAddNewButton`. It is a `GridField_ActionProvider`: every shape is a `GridField_FormAction` carrying `['root' => <container type|leaf class>]`, and `handleAction()` resolves it (leaf classes validated by `ContainerType::Column->isChildCreatable()`), applies both `canCreate()` gates and redirects to the block's editor. There is NO create endpoint — the library listing is not a grid editor, so the control is server-rendered PHP plus its own standalone `client/js/shared-block-add.js`, never the editor bundle. An empty `Title` gets a numbered `SharedBlock.DEFAULT_TITLE` in `onBeforeWrite()`. The stock `item/new` route is closed — `SharedBlockItemRequest::ItemEditForm()` 404s a record not in the DB — so seeded creation is the only path; it would otherwise yield a rootless block that can only ever grow a Section root. The block's **Usage** tab is a read-only `GridField` over `SharedBlockUsageResolver::pagesUsing()`, its link column built with `setFieldFormatting`; never assemble that markup by hand.
- **A placement in a locale implies the block has content in that locale.** `SharedBlockLocaliser::ensureLocalised()` materialises it, called from `FluentGridPageExtension` (page copy) and `SharedBlockService::place()`. It carries the idempotency guard, so it only ever clones into a locale holding nothing — a translation can never be overwritten and two pages placing one block cannot fork it. Two traps: the page-copy pass must walk EVERY cloned node, not just roots (a row-rooted block sits inside a Section), and it must run in the TARGET locale, outside the `withState(source)` block — the guard counts roots in the ACTIVE locale, so running it in the source finds content and skips silently. Localising is additive and cross-page: other pages in that locale placing the same block start rendering too.
- **Never send a `zone` when the parent is a `SharedBlock`.** A block has no zones, `GridElementService::createElement()` forces `Zone = ''` for a non-`SiteTree` parent, and `parseCreateBody()` rejects the empty string the library editor's context holds — so the client omits the field entirely there.
- **The library LISTING offers no removal at all.** `SharedBlockAdmin::getGridFieldConfig()` removes `GridFieldArchiveAction` AND `GridFieldDeleteAction` — the archive action is what suppresses the stock delete for a versioned model (`augmentColumns()`), so removing it alone promotes the row action to a permanent delete. A row cannot ask which outcome the author wants, so removal lives only in the edit form.
- **Deleting a block is always allowed; the MODE is mandatory.** `SharedBlock::canDelete()` does NOT veto on usage. `SharedBlockService::delete($block, SharedBlockDeleteMode)` resolves the placements: `Remove` archives them, `Unshare` replaces each with an independent copy first (publishing the copy where the placement was live). Both reach live without republishing the consuming pages.
- **`SharedBlock::onBeforeDelete()` archives every remaining reference, on both stages.** Placements are page content, so no ownership config reaches them; a stranded reference resolves no effective root class and renders nothing on every page that placed it. Never bypass it by deleting the row directly.
- **Managing a block requires PAGE access — the literal `'CMS_ACCESS_CMSMain'`** — checked by `canEdit`/`canDelete`/`canCreate`, with `SharedBlockAdmin::$required_permission_codes` gating the screen on the same code so API and UI agree. A shared block is page content maintained in one place. Never the bare `CMS_ACCESS`: the framework special-cases it to succeed for ANY `CMS_ACCESS_*` grant, so it gates nothing. Never `'CMS_ACCESS_' . CMSMain::class` either: CMSMain registers its code under the SHORT name, so the FQCN-suffixed variant is a code nobody holds and silently denies every non-admin. `canView` stays on the broad gate on purpose.
- **A placed block renders READ-ONLY in the page editor.** `SharedBlockFrame` provides `PlacementContext` (`client/src/js/components/SharedBlockFrame/PlacementContext.ts`); the editable components consume it to drop drag handles, add/insert buttons, toolbars and title links, and to disable the column size/offset pickers. Nothing inside the frame carries actions — the frame's own bar does, via `SharedPlacementActions` beside its overflow menu: open/edit → `sharedBlock.editLink` (the BLOCK's form in the library, never the root element's), remove → archives the PLACEMENT via the generic delete endpoint. The library editor stays editable because its block-rooted tree contains no placement node, so the context is never provided there. That same absence is why the no-nesting suppressions (place, convert) must key off `GridEditorContext.rootType` and NOT off per-node fields: inside the library editor no node carries `sharedBlockKey`, so a per-node check sees page-local content everywhere.

## Hierarchy Validation

Validation happens in two contexts, both delegating to the hardcoded `ContainerType` rules via the shared `PlacementRulesTrait`.

### At Write Time: `HierarchyValidationExtension`

Applied globally to `GridElement` via YAML. Hooks into `updateValidate()` and delegates to `HierarchyValidationService`:

1. Reference anywhere inside a shared context → fail `SHARED_NESTING`
2. Effective class unresolvable (block empty, or no content in this locale) → **pass**. A block may be emptied long after it was placed, and publish writes every owned root to LIVE, so failing here made consuming pages unpublishable. Refusing to PLACE an empty block is `ReorderValidator`'s job instead.
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

Tests creating container elements **must** account for auto-scaffolded children:

```php
// Creating a Section produces Section + Row + Column (3 elements total)
$section = Section::create();
$section->ParentID = $page->ID;
$section->ParentClass = $page::class;
$section->write();

// The section now has 1 Row child
$this->assertCount(1, $section->getChildren());

// That Row has 1 Column child
$row = $section->getChildren()->first();
$this->assertCount(1, $row->getChildren());
```

### Stage Setup Required

All container integration tests must call `Versioned::set_stage(Versioned::DRAFT)` in `setUp()` because `FlushableTestState::setUp()` clears the reading mode, which would break scaffolding hooks.

## E2E Fixture Ordering

YAML fixtures are written **top-down** (page → section → row → column → leaf) so `=>ClassName.id` parent references resolve. Fixture loading is provided by the `wedevelopnl/silverstripe-e2e` module (dev dependency); the grid declares `config_overrides` on its `FixtureLoader` (in `_config/dev.yml`) forcing `auto_scaffold = false` on `Section` and `Row`, which the module applies via `FixtureBlueprint` callbacks during fixture writes, so parent-first ordering cannot produce duplicate children.

See [docs/testing/e2e-fixtures.md](docs/testing/e2e-fixtures.md) for the full protocol (YAML schema, post-actions, URL segment conventions, the `/dev/e2e-fixtures` HTTP endpoint).
