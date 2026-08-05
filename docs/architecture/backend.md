# Backend Architecture

## Overview

The backend implements a grid-based content block system as a SilverStripe module. It provides a strict three-level container hierarchy (Section > Row > Column > content), a JSON API for the React frontend, and a pluggable CSS framework adapter system. The architecture uses direct polymorphic parent relationships, a layered service design, and the Result pattern for validation flows.

## Data Model

### Element Hierarchy

All grid elements share a single base table (`GridElement`) using SilverStripe's single-table inheritance. The polymorphic `has_one` to `DataObject` allows any element to live under a page or a container without intermediary join tables.

```
SiteTree (page)
  └── Section  [ContainerType::Section]   Zone-scoped, page-level only
        └── Row  [ContainerType::Row]      Cannot be root
              └── Column  [ContainerType::Column]  Stores GridSettings JSON
                    └── (any non-container GridElement)
```

### Polymorphic Parent

```
GridElement
  ParentID    → int     (FK to any DataObject)
  ParentClass → string  (FQCN of the parent record)
  Sort        → int     (ordering within parent, 1-based)
```

Elements link to their parent via `ParentID + ParentClass`. A Section's parent is a `SiteTree` page; a Row's parent is a `Section`; a Column's parent is a `Row`; a content element's parent is a `Column`. This removes the need for intermediary ownership tables — the parent chain is a direct object graph.

The tradeoff: page IDs and element IDs share no namespace separation, so lookup maps must key by the composite `"ParentClass:ParentID"` string, not by `ParentID` alone.

### Container Interface

All three container types implement `ContainerInterface`:

| Method | Returns | Purpose |
|--------|---------|---------|
| `getChildren()` | `HasManyList<GridElement>` | Children of this container |
| `hasChildren()` | `bool` | Whether children exist |
| `getContainerType()` | `ContainerType` | Discriminator enum |

Container behavior (child count summary, simplified class name) lives in `ContainerElementTrait`, shared across Section, Row, and Column.

### Hierarchy Rules

The hierarchy is fixed in code — the `ContainerType` enum (`src/Value/ContainerType.php`), not YAML config. `allowedChildClass()`, `isChildAllowed()`, and `canBeRoot()` are `match` expressions with no configurable inputs:

| Container | Constraint | Enum rule |
|-----------|-----------|-----------|
| Section | Only Rows as children; the only type allowed at page level | `allowedChildClass()` → `Row`; `canBeRoot()` → `true` |
| Row | Only Columns as children; cannot be at page level | `allowedChildClass()` → `Column`; `canBeRoot()` → `false` |
| Column | Any non-container element; cannot be at page level | `allowedChildClass()` → `null` (allows any `GridElement` that is not a `Section`/`Row`/`Column`); `canBeRoot()` → `false` |

Section and Row are strict — only the single mapped child class (or a subclass) is accepted. Column is permissive — `isChildAllowed()` accepts any `GridElement` subclass that is not itself a container. There are no `allowed_elements` / `disallowed_elements` / `can_be_root` YAML statics; those keys are not read anywhere.

### Zone-Scoped Sections

Sections carry a `Zone` field (`Varchar(50)`, e.g., `"main"`, `"sidebar"`) that partitions them within a page. Sort values are independent per zone per parent — the main zone has Sort 1, 2, 3 and the sidebar zone independently has Sort 1, 2, 3. All queries (tree loading, sort assignment, reorder) filter by zone at the root level.

**Why a string field, not a model.** Zone is a partition key — a named slot on a page, not an entity with its own lifecycle. The structural relationship between elements and pages is handled by the polymorphic parent (`ParentID + ParentClass`); Zone simply subdivides that parent's children into independent groups. A separate `Zone` model or intermediary table (as SS5's `ElementalArea` was) would add a DB table, extra writes on every page save, and more complex tree queries — all to accomplish what a simple string field does.

**CMS field exposure.** Zone is an internal system field, never exposed in the CMS edit form. `GridElement::getCMSFields()` explicitly removes it from the scaffolded field list. The zone value is set programmatically when a Section is created via the API — the `GridEditorField` passes its configured zone to the controller, which applies it to new Sections.

**Multi-zone pages.** A page supports multiple zones by adding multiple `GridEditorField` instances, each configured with a different zone string. Each editor mounts as an independent React application with its own API calls, query cache, and tree state. The module ships no page type of its own; `.docker/app/src/MultiZonePage.php` is the test harness's two-zone example.

### Grid Settings

Column stores viewport-specific layout using a `DBComposite` field (`DBGridSettings`) with an intent-based default+overrides model. The composite field maps to four database columns:

| Column | Type | Purpose |
|--------|------|---------|
| `GridSettingsDefaultWidth` | `Int` | Default column span (applies to all viewports) |
| `GridSettingsDefaultOffset` | `Int` | Default column offset (applies to all viewports) |
| `GridSettingsDefaultVisible` | `Boolean` | Default visibility (applies to all viewports) |
| `GridSettingsOverrides` | `Text` | JSON map of per-viewport deviations from the default |

The overrides column holds only the viewports that differ from the default:

```json
{
  "lg": { "width": 6, "offset": 2, "visible": false }
}
```

Two value objects model this data: `ViewportConfig` (readonly record of width/offset/visible) and `GridSettings` (default `ViewportConfig` + map of viewport overrides). `GridSettings` provides `toArray()` for API responses. Serialization to/from database columns is handled entirely by `DBGridSettings` — the value objects have no storage concerns.

`DBGridSettings` extends SilverStripe's `DBComposite` and handles the boundary between the domain model and storage. It converts between `GridSettings` value objects and the four database columns, including parsing legacy JSON strings in the overrides column for fixture compatibility.

`GridSettingsResolver` resolves the effective `ViewportConfig` for each active viewport by applying overrides on top of the default. The resolver supports two strategies via `OverrideStrategy`:
- **Isolated** (default): each viewport uses the default unless it has an explicit override.
- **Cascade**: overrides carry forward to subsequent viewports (mobile-first), configurable via `override_strategy: cascade` on `GridSettingsResolver`.

The Column model exposes `getGridSettings(): GridSettings` and `setGridSettings(GridSettings)` for typed access, routing through `DBGridSettings` via `dbObject()`.

`GridSettingsFieldValidator` validates business rules at the storage layer — width and offset within the adapter's column count, and their combination. Registered on `DBGridSettings` via `$field_validators`, it runs during `DataObject::write()` and blocks writes with invalid settings.

### Auto-Scaffolding

Writing a container on DRAFT stage automatically creates its required child structure:

```
Section::write()
  └── GridElement::onAfterWrite() → creates Row (if no children)
        └── Row::write()
              └── GridElement::onAfterWrite() → creates Column (if no children)
```

Scaffolding lives once in `GridElement::onAfterWrite()` — the child class to create is derived via `ContainerType::allowedChildClass()` rather than duplicated per container subclass.

A single `Section::create()->write()` produces the full three-level tree. Guards ensure idempotency: scaffolding only runs on DRAFT stage and only when the child collection is empty. Column does not auto-scaffold — `allowedChildClass()` returns `null` for leaf containers, so the scaffolding path is skipped.

Auto-scaffolding can be disabled per class via `auto_scaffold: false` in YAML.

## System Boundary

```
┌─────────────────────────────────────────────────────────────┐
│ GridController (AdminController)                            │
│                                                             │
│  HTTP concerns                                              │
│    ├── CSRF validation (SecurityToken)                      │
│    ├── JSON body parsing + type validation                  │
│    ├── Permission checks (canView/canEdit/canDelete/...)    │
│    └── Response mapping (Result → HTTP status + JSON)       │
│                                                             │
├──────────────────── JSON API ───────────────────────────────┤
│                                                             │
│  Service Layer                                              │
│    ├── RequestBodyParser (JSON → typed request DTOs)        │
│    │                                                        │
│    ├── GridTreeService (read path — BFS batch load)         │
│    │     ├── GridElementRepositoryInterface                 │
│    │     └── GridNodeMapper (element → GridNode DTO)        │
│    │                                                        │
│    ├── GridElementService (create + duplicate lifecycle)    │
│    │     ├── ReorderValidatorInterface                      │
│    │     ├── ElementPlacementService                        │
│    │     └── TitleGenerator (copy-of title munging)         │
│    │                                                        │
│    ├── GridSettingsService (column grid settings writes)    │
│    │     ├── GridAdapterInterface                           │
│    │     └── GridTreeService                                │
│    │                                                        │
│    ├── ElementPlacementService (reorder + insert-after)     │
│    │     ├── ReorderValidatorInterface                      │
│    │     └── GridElementRepositoryInterface                 │
│    │                                                        │
│    └── GridSettingsResolver (grid settings resolution)      │
│          └── Resolves ViewportConfig per viewport           │
│                                                             │
│  Validation Layer                                           │
│    ├── HierarchyValidationExtension (write-time hook)       │
│    │     └── HierarchyValidatorInterface                    │
│    ├── GridSettingsFieldValidator (DBField validator)       │
│    │     └── Width/offset/combination range checks          │
│    └── ReorderValidator (implements ReorderValidatorInterface)│
│                                                             │
│  Repository Layer                                           │
│    └── OrmGridElementRepository                             │
│          └── Composite key queries (ParentClass:ParentID)   │
│                                                             │
├──────────────────── Rendering ──────────────────────────────┤
│                                                             │
│  Grid Adapter System                                        │
│    ├── GridAdapterInterface                                 │
│    ├── ContentLayoutAdapterInterface                        │
│    ├── GridAdapter (config-driven base, implements both)    │
│    ├── GridAdapterFactory (DI alias factory)                │
│    ├── Presets: Bootstrap, Tailwind, Bulma (zero-method)    │
│    └── BlockMediaExtension (media/video on content elts)    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## API Layer

### Endpoints

`GridController` extends `AdminController` and uses property injection (`$dependencies`) for all service dependencies. Every mutating endpoint validates the CSRF token and checks permissions before delegating to the service layer.

| Method | Route | Purpose | Response |
|--------|-------|---------|----------|
| GET | `api/readTree/{PageID}/{Zone}` | Load element tree for a zone on a page (draft) | 200 + `{ rootParent: NodeRef, nodes: GridNode[] }` |
| GET | `api/readTree/{PageID}/{Zone}/version/{Version}` | Load element tree at a specific historical version | 200 + `{ rootParent: NodeRef, nodes: GridNode[] }` |
| POST | `api/create` | Create an element under a parent — a container (Section / Row / Column, via `containerType`) or a content element inside a `Column` (via `className`); the controller dispatches on whichever discriminator is present | 204 |
| PATCH | `api/setPublished` | Publish (`published: true`, recursive) or unpublish (`published: false`) an element | 204 |
| DELETE | `api/delete` | Archive an element (id on query string) | 204 |
| POST | `api/duplicate` | Duplicate an element in place (same parent) | 204 |
| POST | `api/duplicateTo` | Duplicate an element into a specific target parent (and target page / zone) | 204 |
| PATCH | `api/reorder` | Reorder or move an element within or across parents | 204 |
| PATCH | `api/updateGridSettings` | Update a column's `GridSettings` for a viewport (default or override) | 204 |
| DELETE | `api/resetGridSettingsOverrides` | Clear viewport overrides across all columns in a page/zone (optionally scoped to one viewport) | 204 |
| GET | `api/acceptableContainers/{PageID}/{Zone}/{ElementType}` | List containers on a page/zone that accept the given element type | 200 + `{ id, title, type }[]` (empty array when `ElementType=section`) |
| GET | `api/zones/{PageID}` | List zones declared by `GridEditorField`s on a page's CMS fields | 200 + `string[]` |
| GET | `api/pages` | List pages (optional `?search=` by title), for the duplicate-to target picker | 200 + `{ id, title, parentId, hasGridZones }[]` |

All mutations return 204 (no body) on success. The frontend refetches the tree after each mutation to reconcile state.

The tree response is a flat `nodes` array of root children plus an explicit `rootParent: NodeRef` — see [Node Identity](#node-identity) below. Override-count summaries for the "overrides exist" indicator are derived on the client from the returned `GridSettings` rather than sent from the server.

### Request Validation

The controller delegates body parsing to `RequestBodyParser`, which returns typed request DTOs wrapped in `Result`. Each `parseX()` method returns `Result::fail()` for invalid payloads:

- `parseCreateBody()` — validates `containerType` (enum), `parent` (`NodeRef`), `insertAfterElementID`, `insertAtStart` (bool), and `zone`. Enforces that `insertAtStart` and `insertAfterElementID` are mutually exclusive
- `parseCreateContentBody()` — validates `className` (must be `ContentElement` subclass), `parent` (`NodeRef`), `insertAfterElementID`
- `parseReorderBody()` — validates `element` / `parent` / `after` (all `NodeRef`), with `after.type === element.type` and `element.type !== page`
- `parseUpdateGridSettingsBody()` — validates `element` (`NodeRef`) plus viewport-scoped width/offset/visibility (viewport must match an adapter viewport)
- `parseDuplicateToBody()` — validates source `element` (`NodeRef`), `targetPageId`, `targetZone`, and `targetParent` (`NodeRef`)
- `parseResetGridSettingsOverridesBody()` — validates `pageId`, `zone`, and optional `viewport` filter (rejects the adapter default viewport — it has no overrides to reset)
- `parseElementRef()` — validates a `NodeRef` `{type, id}` envelope under the `element` key (rejecting `NodeType::Page`); used by the single-target mutation endpoints (publish/unpublish, archive, duplicate)

Invalid payloads produce HTTP 400. Validation failures from the service layer produce HTTP 422 with structured error JSON.

### Permission Model

| Check | Applies to |
|-------|-----------|
| CSRF token | All mutations (controller `init()` rejects non-GET without valid token) |
| `canView()` on page | `readTree`, `readTreeAtVersion`, `acceptableContainers`, `zones` |
| `canEdit()` on target parent | Create, reorder, duplicateTo |
| `canEdit()` on page | `resetGridSettingsOverrides` |
| `canCreate()` on element | Duplicate, duplicateTo |
| `canEdit()` on element | Reorder, updateGridSettings |
| `canEdit()` on source parent | Duplicate (in-place); cross-parent reorder |
| `canDelete()` on element | Delete |
| `canPublish()` / `canUnpublish()` | Publish / unpublish |
| `canEdit()` on pages in result | `pages` (list is filtered to editable pages) |

Permission resolution delegates to the owning page: `GridElement.canEdit()` walks the parent chain to the nearest `SiteTree` and calls `canEdit()` on it. Orphaned elements (no page in chain) fall back to `CMS_ACCESS` permission.

### Client Configuration

`getClientConfig()` exposes the grid adapter configuration to the frontend via SilverStripe's admin client config mechanism:

```php
[
    'viewports'        => [['key' => 'xs', 'label' => 'Extra small'], ...],
    'defaultViewport'  => 'md',
    'columnCount'      => 12,
    'rowClasses'       => 'row',
    'offsetStrategy'   => 'margin',
    'baseWidthClasses' => {'1': 'col-1', '2': 'col-2', ...},
    'baseOffsetClasses'=> {'0': 'offset-0', '1': 'offset-1', ...},
]
```

This allows the frontend grid editor to render column width previews and viewport controls without knowing the concrete CSS framework.

## Service Layer

### RequestBodyParser

Turns raw JSON arrays from incoming `HTTPRequest` bodies into typed, validated request DTOs (`CreateElementRequest`, `CreateContentRequest`, `ReorderRequest`, `DuplicateToRequest`, `UpdateGridSettingsRequest`, `ResetGridSettingsOverridesRequest`). Each `parseX()` method returns a `Result` — invalid payloads fail with a `ValidationError` carrying a specific field name, which the controller translates to HTTP 400. The parser is injected with `GridAdapterInterface` so that viewport-scoped request fields can be validated against the configured viewport set.

### GridElementService

Domain service for element creation and duplication lifecycle (`createElement`, `createContentElement`, `duplicateElement`, `duplicateElementTo`). Mirrors `ElementPlacementService`'s contract: receives already-loaded, already-authorized objects and returns a `Result<GridElement>`. All writes go through `WriteResult::from()` so that thrown `ValidationException`s surface as `Result::fail()` failures.

Creation paths (`createElement`, `createContentElement`, in-place `duplicateElement`) write the new element inside a DB transaction and then delegate placement to `ElementPlacementService::insertAfter()` so the same validator and reindex pipeline runs for both "just-written" and "moving" elements. If placement fails, the transaction rolls back — callers never observe a half-persisted element paired with a failure `Result`.

Cross-page duplication (`duplicateElementTo`) additionally validates ownership (C1: target parent belongs to the claimed page/zone) and hierarchy (C2: element type is allowed under target parent) before deep-copying the subtree. Copy titles are generated via `TitleGenerator::generateCopyTitle()`.

### GridSettingsService

Domain service for column `GridSettings` mutations. `updateSettings()` applies viewport-scoped width/offset/visibility changes — writes to the default config when the targeted viewport matches the adapter default, otherwise to an override, and automatically drops overrides that collapse back to the default (redundant-override cleanup). `resetOverrides()` clears every column's viewport overrides across a page/zone (optionally scoped to a single viewport) in a single pass. Depends on `GridAdapterInterface` (to identify the default viewport) and `GridTreeService` (to list the page's elements for bulk reset; `resetOverrides()` filters the flat list down to Columns itself).

### GridTreeService

Builds the full element tree for a page using breadth-first batch loading — one query per hierarchy depth level.

```
buildViewableTree(page, zone)
  │
  ├─ loadAllElements()
  │    ├─ Level 0: query Sections by page (zone-filtered)
  │    ├─ Level 1: query Rows by all Section IDs
  │    ├─ Level 2: query Columns by all Row IDs
  │    └─ Level 3: query content elements by all Column IDs
  │
  └─ assembleSubTree()
       └─ Recursive in-memory assembly + GridNodeMapper conversion
```

Elements are keyed by the composite `"ParentClass:ParentID"` string in the lookup map. This prevents false matches when a page ID coincides with an element ID.

`GridTreeService` owns loading and tree assembly; the `GridNodeMapper` it holds owns element → `GridNode` conversion. The seam is deliberately tight: the service supplies only structure (the element, its parent ref, its assembled children) via `mapToNode(element, parent, children)`, and the mapper derives everything else — `containerType`, `allowedTypes`, `gridSettings` — from the element itself. Split so the mapper can be reused (e.g. the `updateElementData` hook fires once per node regardless of which loading strategy is used) and so the service's own concerns stay free of view-layer details like icon fallbacks and block schemas.

The public API is layered by permission handling: mechanism-layer methods never filter, policy-layer methods (the `Viewable` names) apply `canView()`:

- `buildViewableTree(page, zone)` — the CMS React API's wire model (`GridTree`: root parent ref + nodes), filtered per node by `canView()` (policy).
- `findDescendantsForPage(page, zone)` — flat list of every element on the page/zone in BFS level order; callers filter (e.g. `GridSettingsService::resetOverrides()` keeps only Columns) (mechanism).
- `findDescendants(element)` — flat subtree below any element, root excluded, no zone filter (mechanism).
- `findViewableContainersOfType(page, zone, ContainerType)` — viewable container elements of one type; backs `apiAcceptableContainers`, where the controller builds the `{id, title, type}` tuples via `GridElement::getDisplayTitle()` (policy).
- `indexByKey(elements)` / `ancestors(element, index)` — O(1) `"Class:ID"` index plus ancestor walk (outermost first), used by `GridElementReport` for location trails (mechanism).

### GridNodeMapper

Converts a single `GridElement` to a `GridNode` DTO. Reads title (with `(untitled)` fallback), block schema, icon, `canView/canEdit/canCreate/canDelete/canPublish/canUnpublish`, a precomputed `ElementStatus` enum (`draft | published | modified | removed`) derived from `getStatusFlags()`, and `getSummary()` output.

Caches allowed-child-type enumeration per container class (`getAllowedTypes()`) so walking a tree of many Sections doesn't repeat `ClassInfo::subclassesFor()` work per node.

Exposes the `updateElementData` extension point: other modules can inject additional fields into each node's `extensions` array.

### ElementPlacementService

Single write-side authority for element placement. Two entry points, one pipeline:

- `reorder(element, targetParent, afterElementId)` — move an already-placed element.
- `insertAfter(element, parent, afterElementId)` — place a just-written element after a reference sibling.

Both splice the element into its parent's sibling list and reindex `Sort`. The two methods exist to let call sites express intent; they delegate to the same validator and DB path. `GridElementService` uses `insertAfter()` after writing each newly-created or in-place-duplicated element so every placement goes through the shared validator.

```
reorder(element, targetParent, afterElementId)
  │
  ├─ Validate (ReorderValidatorInterface)
  │    └─ Hierarchy rule check (applies to both same- and cross-parent)
  │
  ├─ Compute sort order (in-memory)
  │    └─ Load siblings, splice, reindex
  │
  └─ Persist dirty elements (wrapped in DB transaction)
       └─ WriteResult catches ValidationException → Result
```

Each step returns a `Result`. If any step fails, the service short-circuits and the failure propagates to the controller.

### Sort Computation

`ElementPlacementService` computes new Sort values entirely in memory:

1. Load target siblings (zone-filtered for Sections)
2. Exclude the moved element from the sibling list
3. Resolve insertion index from `afterElementId` (`null` means first position; missing reference sibling returns `Result::fail`)
4. `array_splice()` the element into position
5. Reindex Sort values (1-based: 1, 2, 3, ...)
6. Track dirty elements (only those whose `Sort` or `ParentID` actually changed)

For cross-parent moves, the source parent's siblings are also reindexed to close the gap left by the moved element. The moved element is marked always-dirty even if its Sort value happens to stay the same, because its `ParentID` has changed.

Persistence runs inside a `DB::get_conn()->withTransaction()` so a mid-loop write failure rolls the entire batch back rather than leaving siblings half-reindexed.

### TitleGenerator

Produces `"Original title (Copy)"`, `"Original title (Copy 2)"`, ... titles for `duplicateElement()` and `duplicateElementTo()`. Separated so both duplication paths produce the same user-visible naming convention.

### WriteResult

The boundary where SilverStripe's `ValidationException` becomes a domain `Result`. Used by `ElementPlacementService` and `GridElementService` to persist writes and by other write operations throughout the codebase.

### GridSettingsResolver

Resolves the effective `ViewportConfig` for each active viewport from a `GridSettings` value object. The resolver applies the adapter's `OverrideStrategy`:

- **Isolated** (default): each viewport gets the default config unless it has an explicit override entry.
- **Cascade**: overrides carry forward to subsequent viewports in order, implementing mobile-first inheritance.

`ColumnClassResolver` calls the resolver to obtain a `array<string, ViewportConfig>` map, then generates CSS classes by comparing each viewport's effective config to the previous one.

## Validation Layer

Hierarchy validation runs in two contexts with shared logic:

### Write-Time: HierarchyValidationExtension

Applied globally to `GridElement` via YAML. Hooks into `updateValidate()` in the SilverStripe write lifecycle:

1. No parent (orphan) → pass
2. Parent is `SiteTree` → check the element's `ContainerType::canBeRoot()`
3. Parent is a container → check the parent's `ContainerType::isChildAllowed($element::class)`

Violations throw `ValidationException`, preventing the database write.

### Reorder-Time: ReorderValidator

Called by `ElementPlacementService` before executing a placement — on both the `reorder()` (move existing element) and `insertAfter()` (place just-written element) paths, so newly-created and duplicated elements run the same checks. Applies the same hierarchy rules as the write-time extension but returns `Result::fail()` instead of throwing, maintaining the Result pattern contract.

Same-parent moves skip validation entirely — reordering within a container cannot violate hierarchy rules.

### Shared authority: `ContainerType`

Both `HierarchyValidationService` (write-time) and `ReorderValidator` (reorder-time) delegate the actual allow/deny decision to the target parent's `ContainerType` enum — `canBeRoot()` for page-level placement and `isChildAllowed($element::class)` for container placement. There is a single source of truth for the rules and no separate allowance trait or config lookup.

## Repository Layer

`GridElementRepositoryInterface` provides four query methods:

| Method | Purpose |
|--------|---------|
| `findById(int)` | Single element lookup by ID |
| `findByRef(NodeRef)` | Lookup by scoped `NodeRef` — the controller's primary lookup (resolves the `NodeType` to the right ORM class); returns `null` for non-element types (e.g. Page) |
| `findByParentIds(list<int>, string $parentClass)` | Elements by ParentID + parent class |
| `findByParents(array<class, list<int>>, ?zone)` | Composite key + optional zone filter |

`OrmGridElementRepository` implements these against the SilverStripe ORM. All queries sort by `Sort ASC, ID ASC`. Zone filtering queries the `Section` table directly (the `Zone` column only exists there).

## Result Pattern

Service-layer operations return `Result<T>` for expected validation failures. Exceptions are reserved for programming errors and infrastructure failures.

```php
Result::ok($value)              // Success, carries the value
Result::fail($error, ...$rest)  // Failure, carries ValidationError[]
```

| Method | Purpose |
|--------|---------|
| `isOk()` / `isErr()` | Check outcome |
| `unwrap()` | Access success value (throws on failure — programmer bug) |
| `errors()` | Access validation errors |
| `map(fn)` | Transform success value, no-op on failure |

The controller maps `Result::ok()` to HTTP 204 and `Result::fail()` to HTTP 422 with error messages.

## Grid Adapter System

Grid adapters translate the abstract layout model (viewports, column widths, offsets, visibility) into CSS framework-specific class names. All consumers depend on `GridAdapterInterface`, never on a concrete adapter.

### Interface Contract

| Method | Returns | Purpose |
|--------|---------|---------|
| `getViewports()` | `list<Viewport>` | Active viewport breakpoints |
| `getColumnCount()` | `positive-int` | Total grid columns |
| `getDefaultViewport()` | `Viewport` | Default/base viewport |
| `getWidthClass(viewport, width)` | `string` | Width class for viewport |
| `getOffsetClass(viewport, offset)` | `string` | Offset class for viewport |
| `getBaseWidthClass(width)` | `string` | Width class for base viewport |
| `getBaseOffsetClass(offset)` | `string` | Offset class for base viewport |
| `getHideClass(viewport)` | `string` | Hide class for viewport |
| `getRestoreClass(viewport)` | `?string` | Restore-visibility class, or null when the framework's hides are viewport-scoped (e.g. Bulma) |
| `getRowClasses()` | `string` | Row container classes |
| `getContainerClass(fluid)` | `string` | Container wrapper classes |
| `getTitleClassOptions()` | `array<string, string>` | CSS class to label mapping |
| `getOffsetStrategy()` | `OffsetStrategy` | Margin-based vs grid-placement |
| `getContainerMaxWidth()` | `positive-int` | Max container width in px (for responsive images) |
| `getColumnPixelWidth(columnSpan)` | `positive-int` | Pixel width of a column span at max container width (for responsive images) |

### Config-Driven Base Class

`GridAdapter` is the single `abstract` base class implementing both `GridAdapterInterface` and `ContentLayoutAdapterInterface`. All CSS class generation is driven by Configurable static properties — format strings, class maps, and scalar values. Framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter) are zero-method subclasses that only override static properties, and are the only concrete (instantiable) adapters.

YAML-configurable properties (set on the concrete preset class):

| Property | Type | Effect |
|----------|------|--------|
| `enabled_viewports` | `list<string>\|null` | Restrict active viewports |
| `total_columns` | `positive-int` | Grid column count |
| `default_viewport` | `string` | Default viewport for CMS editor |
| `container_max_width` | `positive-int` | Max container width |
| `base_viewport_key` | `?string` | Viewport using base (no-infix) format |
| `base_width_format` / `responsive_width_format` | `string` | sprintf format strings for width classes |
| `base_offset_format` / `responsive_offset_format` | `string` | sprintf format strings for offset classes |
| `offset_adjustment` | `int` | Added to offset before formatting (0 or 1) |
| `aspect_ratio_classes` | `array<string, ?string>` | AspectRatio value → CSS class |
| ... | | (see `GridAdapter` docblock for the full 25+ properties) |

The constructor reads all config, builds Viewport objects, validates topology, and pre-computes the visibility map. Invalid configuration throws `InvalidGridValueException`.

### Override Strategy (Module Config)

The override strategy is a module-level setting on `GridSettingsResolver`, not the adapter:

```yaml
WeDevelop\Grid\Service\GridSettingsResolver:
  override_strategy: cascade
```

### Adapters

| Adapter | Viewports | Base viewport | Width pattern |
|---------|-----------|--------------|---------------|
| Bootstrap | xs, sm, md, lg, xl, xxl | xs (no infix) | `col-{vp}-{n}` |
| Tailwind | sm, md, lg, xl, 2xl | sm | `{vp}:col-span-{n}` |
| Bulma | mobile, tablet, desktop, widescreen, fullhd | mobile (no suffix) | `is-{n}-{vp}` |

There is no compile-time default. `GridAdapterInterface` is bound through a factory that selects the active adapter from the **required** `SS_GRID_ADAPTER` env var — a bundled preset name (`bootstrap`, `tailwind`, or `bulma`, case-insensitive) or the FQCN of a custom adapter implementing `GridAdapterInterface`. Unset, empty, or invalid values throw at container boot:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    factory: WeDevelop\Grid\Factory\GridAdapterResolver
```

See [Grid Adapter System](grid-adapter.md) for the full selection and configuration reference.

## Content Layout System

The content layout system adds media (image/video) capability with side-by-side layout to content elements. It complements the grid adapter system: grid handles column widths and offsets, content layout handles aspect ratios, ordering, alignment, and directional padding.

### Architecture

```
BlockMediaExtension (opt-in — applied to ContentElement by the project)
  └── ContentLayoutAdapterInterface
        └── GridAdapter (same instance as GridAdapterInterface)
```

Content layout is implemented directly by `GridAdapter` — the same adapter instance serves both `GridAdapterInterface` and `ContentLayoutAdapterInterface`. Content layout CSS strings are Configurable statics on the adapter alongside grid CSS strings. No separate adapter or data bag needed.

### ContentLayoutAdapterInterface

| Method | Returns | Purpose |
|--------|---------|---------|
| `getAspectRatioClass(AspectRatio)` | `?string` | Aspect ratio constraint (null for Auto) |
| `getVerticalAlignmentClass(VerticalAlignment)` | `string` | Flex/grid row alignment |
| `getMediaOrderClasses(MediaPosition)` | `string` | CSS order for media column |
| `getContentOrderClasses(MediaPosition)` | `string` | CSS order for content column |
| `getMediaWidthClass(int)` | `string` | Width class for media column |
| `getContentWidthClass(int)` | `string` | Width class for content column |
| `getPaddingClass(direction, size)` | `string` | Directional padding/margin for gap |
| `getBaseColumnClass()` | `?string` | Framework base class (e.g. Bulma's `column`) |

Width classes delegate to `getWidthClass()` on the same adapter — `getMediaWidthClass()` computes `totalColumns - contentColumns`. Order classes use the adapter's default viewport for responsive breakpoint resolution.

### BlockMediaExtension

**Opt-in.** The module deliberately does *not* apply this extension — `ContentElement` ships lean (HTML only), and `_config/content-layout.yml` carries the opt-in snippet as a comment. Apply it from your own project config to add media attachment and layout controls to `ContentElement` (or to your own content subclass).

Note for migrations: the SS5→SS6 default class map targets `ContentElement`, so a project migrating media data must opt the extension in first, or remap `FieldMapper::classNameMap` to its own media class. See [migration.md](../migration.md).

**Database fields** (15 fields via `$db`):

| Group | Fields |
|-------|--------|
| Layout | `ContentColumns` (int), `VerticalAlignment`, `GapSize` (int), `MediaPosition` |
| Image | `MediaCaption`, `MediaRatio` |
| Video | `VideoURL`, `VideoProvider`, `VideoHasOverlay`, `VideoEmbedName`, `VideoEmbedURL`, `VideoEmbedDescription`, `VideoEmbedThumbnail`, `VideoEmbedCreated` |
| Media type | `MediaType` (image/video discriminator) |

**Relationships**: `has_one` to `MediaImage` and `VideoCustomThumbnail` (both `Image`), with `owns`, `cascade_deletes`, and `cascade_duplicates`.

**Responsive image sizing**: Calculates pixel width from the ratio of content columns to total grid columns, multiplied by `GridAdapterInterface::getContainerMaxWidth()`. Resizes images via SilverStripe's `Fill()` (when aspect ratio set) or `ScaleWidth()` (auto ratio).

**Template integration**: The `WeDevelop/Grid/Includes/MediaBlock` template renders the side-by-side layout with content and media columns, aspect ratio wrapper, and `<figure>/<figcaption>` markup.

### DI Configuration

What the module ships:

```yaml
# _config/content-layout.yml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\ContentLayoutAdapterInterface:
    factory: WeDevelop\Grid\Factory\GridAdapterFactory
```

What a project adds to opt into the media block:

```yaml
# app/_config/grid.yml
WeDevelop\Grid\Model\ContentElement:
  extensions:
    - WeDevelop\Grid\Extensions\BlockMediaExtension
```

`GridAdapterFactory` resolves `GridAdapterInterface` from the Injector and returns the same singleton, so both interfaces share one adapter instance. The factory pattern is used instead of a `%$` alias because it guarantees the singleton is fully constructed before being returned.

## Node Identity

Pages (`SiteTree`) and grid elements live in separate DB tables with independent auto-increment sequences, so a page and an element can share the same numeric ID. Anywhere identity is stored as a bare int is a latent collision bug; the wire format pairs every ID with a discriminator.

| Value | Shape | Role |
|-------|-------|------|
| `NodeType` | Enum: `page`, `section`, `row`, `column`, `element` | Discriminator. `fromClass()` resolves a FQCN to the right case; `toClass()` returns the canonical ORM class for lookup (with `element` → `GridElement`). |
| `NodeRef` | `final readonly { type: NodeType, id: positive-int }` | Scoped identity used on every API boundary — tree responses (`rootParent`), reorder payload (`element`, `parent`, `after`), duplicate-to targets, create parents. Serializes to `{ type, id }` via `JsonSerializable`. |

Server and client use the same shape: the frontend `NodeKey` (`"${NodeType}-${id}"`) string form is produced by `NodeRef::toKey()`. The controller uses `resolveNodeRef()` to pick the right ORM class before loading, avoiding the polymorphic ID collision.

## Value Objects

| Class | Purpose |
|-------|---------|
| `ContainerType` | Enum: Section, Row, Column — with `toElementClass()`, `allowedChildClass()`, `isChildAllowed()` |
| `NodeType` | Enum: Page, Section, Row, Column, Element — scoped identity discriminator (see [Node Identity](#node-identity)) |
| `NodeRef` | `final readonly { type, id }` — canonical on-the-wire element reference |
| `Viewport` | `final readonly class` with `key` and `label` |
| `GridNode` | Readonly DTO for serialized tree nodes (includes `self: NodeRef`, `parent: NodeRef`, `status: ElementStatus`, `summary`, and optional `containerType`/`allowedTypes`/`children`/`gridSettings`) |
| `ElementStatus` | Enum: Draft, Published, Modified, Removed — derived from `getStatusFlags()` |
| `Result<T>` | Generic success/failure container |
| `ValidationError` | Structured error with message, field, severity, code, and optional i18n key + params |
| `ValidationErrorCode` | Enum: Generic, OwnershipDenied, HierarchyViolation, InvalidGridSettings |
| `ValidationSeverity` | Enum: Error, Warning |
| `AspectRatio` | Enum: Auto, Square (1x1), FourByThree (4x3), SixteenByNine (16x9) |
| `MediaPosition` | Enum: First, Last, LastOnDesktop |
| `VerticalAlignment` | Enum: Top, Center, Bottom |
| `ViewportConfig` | Readonly record: width, offset, visible for a single viewport |
| `GridSettings` | Default `ViewportConfig` + map of per-viewport overrides |
| `OverrideStrategy` | Enum: Isolated (per-viewport), Cascade (mobile-first carry-forward) |
| `OffsetStrategy` | Enum: Margin, GridPlacement — how offsets translate to CSS |
| `WriteResult` | Converts thrown `ValidationException` into `Result::fail()` |

## Dependency Injection

Two injection styles coexist due to SilverStripe framework constraints:

**Property injection** (`$dependencies` array) — used by controllers and elements because the framework instantiates them without DI arguments:

```php
private static array $dependencies = [
    'gridAdapter' => '%$' . GridAdapterInterface::class,
];
public GridAdapterInterface $gridAdapter;
```

**Constructor injection** — used by services, with explicit `constructor:` config in YAML because the Injector does not auto-wire constructor parameters from interface bindings:

```yaml
WeDevelop\Grid\Service\ElementPlacementService:
  constructor:
    validator: '%$WeDevelop\Grid\Contract\ReorderValidatorInterface'
    elementRepository: '%$WeDevelop\Grid\Repository\GridElementRepositoryInterface'
```

## CMS Integration

`GridPageExtension` is opt-in — consuming projects apply it to the page classes they want grid editing on (see the module README for setup). Applying it:

- Declares `has_many` to Section (with `owns`, `cascade_deletes`, `cascade_duplicates`)
- Adds a `UseGrid` boolean DB field (defaulting to `$use_grid_by_default`)
- Removes the default `Content` field when the grid is active
- Injects `GridEditorField` as the React mount point for the grid editor

### Per-page editor toggle

Two class-level statics control the toggle behavior (both overridable per page subclass via YAML):

- `$use_grid_by_default` (default `true`) — initial value of `UseGrid` on newly populated pages (`onAfterPopulateDefaults`).
- `$enable_editor_toggle` (default `false`) — when `true`, a "Use grid on this page" checkbox appears in the CMS form and the editor rendered reflects the stored `UseGrid`. When `false`, the grid is always rendered regardless of the stored value.

`GridEditorField` is a lightweight `FormField` subclass that renders data attributes (`pageId`, `zone`) and delegates all mutations to the API controller. Its `saveInto()` is a no-op — the grid editor manages persistence through the JSON API, not through the CMS form save cycle.

### Historical tree (version history)

`GridAwareVersionFormFactory` is registered as the `DataObjectVersionFormFactory` alias in `_config/history-viewer.yml`. It reproduces the stock factory's pipeline with one difference: `GridEditorField` survives the `GridField` strip step so the grid editor renders inside the history viewer. `apiReadTreeAtVersion` powers that view by loading the tree in `Versioned` archived reading mode.

A known limitation: archive cutoff is derived from the page version's `LastEdited` which has second precision — rapid sub-second successive publishes of the same page can leak sibling element writes into the historical snapshot. See `GridController::apiReadTreeAtVersion()` docblock for details.

### CMS Reports

`GridElementReport` (`src/Reports/GridElementReport.php`) registers a CMS report listing grid elements with optional `orphaned` filtering. Requires `silverstripe/reports` as an optional dependency.

### Fluent integration (optional)

When `silverstripe-fluent` is installed, `_config/fluent.yml` wires:

- `FluentGridPageExtension` onto `SiteTree` — hooks `onAfterCopyLocale` and `onAfterLocalisedCopy` to duplicate the grid subtree on copy.
- `GridAwareDeleteLocalisationPolicy` as the Injector alias for `DeleteLocalisationPolicy` — wraps Fluent's original policy and additionally cascades grid element deletion when a locale is cleared.

See `docs/fluent.md` for full setup.

## Extension Points

| Hook | Location | Purpose |
|------|----------|---------|
| `updateContainerClasses` | Section | Modify container CSS classes |
| `updateColumnClasses` | Column | Modify column CSS classes |
| `updateElementData` | GridNodeMapper | Inject extra data into tree nodes |
| `extendedCan` | GridElement | Override permission checks |
| `updateValidate` | HierarchyValidationExtension | Intercept validation lifecycle |
| `updateCMSFields` | BlockMediaExtension | Inject media/layout fields into CMS form |
