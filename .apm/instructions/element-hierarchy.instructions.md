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

Sections carry a `Zone` field (e.g., `"main"`, `"sidebar"`) scoping them within a page. Sort values are independent per zone per parent. All queries (tree loading, sort assignment, reorder) filter by zone at the root level.

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

Titles for newly scaffolded children are blank by default. `GridElement::ensureDefaultTitle()` applies a translatable fallback (`GridElement.DEFAULT_TITLE`) at render time when the stored `Title` is empty.

## Hierarchy Validation

Validation happens in two contexts, both delegating to the hardcoded `ContainerType` rules.

### At Write Time: `HierarchyValidationExtension`

Applied globally to `GridElement` via YAML. Hooks into `updateValidate()` and delegates to `HierarchyValidationService`:

1. No parent → pass (orphan)
2. Parent is a SiteTree page → check `getContainerType()->canBeRoot()` on the element
3. Parent is a container → check `getContainerType()->isChildAllowed($element::class)` on the parent

Violation throws `ValidationException`, preventing the database write.

### At Reorder Time: `ReorderValidator`

Called by `ElementPlacementService` (used for both `reorder()` and `insertAfter()` paths, including placement of newly-written elements from `GridElementService`):

1. Applies the `canBeRoot()` and `isChildAllowed()` checks (both on `ContainerType`) against the target parent
2. Returns `Result::fail()` for violations (uses Result pattern, not exceptions)
3. Same-parent moves pass the checks trivially — hierarchy cannot have changed

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

See [docs/testing/e2e-fixtures.md](../../docs/testing/e2e-fixtures.md) for the full protocol (YAML schema, post-actions, URL segment conventions, the `/dev/e2e-fixtures` HTTP endpoint).
