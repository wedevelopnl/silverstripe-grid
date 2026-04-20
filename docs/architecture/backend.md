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

### Hierarchy Rules (YAML)

| Container | Constraint | Mechanism |
|-----------|-----------|-----------|
| Section | Only Rows as children | `allowed_elements: [Row]` |
| Row | Only Columns as children, cannot be at page level | `allowed_elements: [Column]`, `can_be_root: false` |
| Column | Any non-container content element | `disallowed_elements: [Section, Row, Column]` |

The allowlist approach on Section and Row is strict: only the listed classes are accepted. The blocklist approach on Column is permissive: any `GridElement` subclass is accepted unless explicitly excluded.

### Zone-Scoped Sections

Sections carry a `Zone` field (`Varchar(50)`, e.g., `"main"`, `"sidebar"`) that partitions them within a page. Sort values are independent per zone per parent — the main zone has Sort 1, 2, 3 and the sidebar zone independently has Sort 1, 2, 3. All queries (tree loading, sort assignment, reorder) filter by zone at the root level.

**Why a string field, not a model.** Zone is a partition key — a named slot on a page, not an entity with its own lifecycle. The structural relationship between elements and pages is handled by the polymorphic parent (`ParentID + ParentClass`); Zone simply subdivides that parent's children into independent groups. A separate `Zone` model or intermediary table (as SS5's `ElementalArea` was) would add a DB table, extra writes on every page save, and more complex tree queries — all to accomplish what a simple string field does.

**CMS field exposure.** Zone is an internal system field, never exposed in the CMS edit form. `GridElement::getCMSFields()` explicitly removes it from the scaffolded field list. The zone value is set programmatically when a Section is created via the API — the `GridEditorField` passes its configured zone to the controller, which applies it to new Sections.

**Multi-zone pages.** A page supports multiple zones by adding multiple `GridEditorField` instances, each configured with a different zone string. Each editor mounts as an independent React application with its own API calls, query cache, and tree state. See `MultiZonePage` for the dev environment example.

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
  └── onAfterWrite() → creates Row (if no children)
        └── Row::write()
              └── onAfterWrite() → creates Column (if no children)
```

A single `Section::create()->write()` produces the full three-level tree. Guards ensure idempotency: scaffolding only runs on DRAFT stage and only when the child collection is empty. Column does not auto-scaffold — it only initializes `GridSettings` on first write.

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
│    ├── GridTreeBuilder (read path)                          │
│    │     └── GridElementRepositoryInterface                 │
│    │                                                        │
│    ├── GridElementService (create + duplicate lifecycle)    │
│    │                                                        │
│    ├── GridSettingsService (column grid settings writes)    │
│    │     ├── GridAdapterInterface                           │
│    │     └── GridTreeBuilder                                │
│    │                                                        │
│    ├── ReorderService (validate + reorder + persist)        │
│    │     ├── ReorderValidatorInterface                      │
│    │     └── GridElementRepositoryInterface                 │
│    │                                                        │
│    └── GridSettingsResolver (grid settings resolution)      │
│          └── Resolves ViewportConfig per viewport           │
│                                                             │
│  Validation Layer                                           │
│    ├── HierarchyValidationExtension (write-time hook)       │
│    │     └── HierarchyValidatorInterface                    │
│    ├── GridSettingsFieldValidator (DBField validator)        │
│    │     └── Width/offset/combination range checks          │
│    └── ElementAllowanceTrait (shared allowlist/blocklist)   │
│                                                             │
│  Repository Layer                                           │
│    └── OrmGridElementRepository                             │
│          └── Composite key queries (ParentClass:ParentID)   │
│                                                             │
├──────────────────── Rendering ──────────────────────────────┤
│                                                             │
│  Grid Adapter System                                        │
│    ├── GridAdapterInterface (14 methods)                    │
│    ├── ContentLayoutAdapterInterface (8 methods)            │
│    ├── GridAdapter (config-driven base, implements both)    │
│    ├── GridAdapterFactory (DI alias factory)                │
│    ├── Presets: Bootstrap, Tailwind, Bulma (zero-method)   │
│    └── BlockMediaExtension (media/video on content elts)   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## API Layer

### Endpoints

`GridController` extends `AdminController` and uses property injection (`$dependencies`) for all service dependencies. Every mutating endpoint validates the CSRF token and checks permissions before delegating to the service layer.

| Method | Route | Purpose | Response |
|--------|-------|---------|----------|
| GET | `api/readTree/{PageID}/{Zone}` | Load element tree for a zone on a page (draft) | 200 + `{ tree: Record<int, GridNode[]>, overrideCounts: object }` |
| GET | `api/readTree/{PageID}/{Zone}/version/{Version}` | Load element tree at a specific historical version | 200 + `{ tree: Record<int, GridNode[]>, overrideCounts: object }` |
| POST | `api/create` | Create a container element (Section / Row / Column) under a parent | 204 |
| POST | `api/createContent` | Create a content element inside a `Column` | 204 |
| PATCH | `api/publish` | Publish an element recursively | 204 |
| PATCH | `api/unpublish` | Unpublish an element | 204 |
| DELETE | `api/delete` | Archive an element | 204 |
| POST | `api/duplicate` | Duplicate an element in place (same parent) | 204 |
| POST | `api/duplicateTo` | Duplicate an element into a specific target parent (and optional page / zone) | 204 |
| PATCH | `api/reorder` | Reorder or move an element within or across parents | 204 |
| PATCH | `api/updateGridSettings` | Update a column's `GridSettings` for a viewport (default or override) | 204 |
| DELETE | `api/resetGridSettingsOverrides` | Clear viewport overrides across all columns in a page/zone (optionally scoped to one viewport) | 204 |
| GET | `api/acceptableContainers/{PageID}/{Zone}/{ElementType}` | List containers on a page/zone that accept the given element type | 200 + `GridNode[]` (empty array when `ElementType=section`) |
| GET | `api/zones/{PageID}` | List zones declared by `GridEditorField`s on a page's CMS fields | 200 + `string[]` |
| GET | `api/pages` | List pages (optional `?search=` by title), for the duplicate-to target picker | 200 + `{ id, title, parentId, hasGridZones }[]` |

All mutations return 204 (no body) on success. The frontend refetches the tree after each mutation to reconcile state.

### Request Validation

The controller delegates body parsing to `RequestBodyParser`, which returns typed request DTOs wrapped in `Result`. Each `parseX()` method returns `Result::fail()` for invalid payloads:

- `parseCreateBody()` — validates `elementClass` (must be `GridElement` subclass), `parentId`, `parentClass`, `insertAfterElementID`, `zone`
- `parseCreateContentBody()` — validates content element creation fields
- `parseReorderBody()` — validates `elementID`, `targetParentId`, `afterElementID`
- `parseUpdateGridSettingsBody()` — validates viewport-scoped width/offset/visibility
- `parseDuplicateToBody()` — validates target page, zone, and container
- `parseResetGridSettingsOverridesBody()` — validates page/zone scope and optional viewport filter
- `parseElementId()` — validates a single `id` field

Invalid payloads produce HTTP 400. Validation failures from the service layer produce HTTP 422 with structured error JSON.

### Permission Model

| Check | Applies to |
|-------|-----------|
| CSRF token | All mutations |
| `canView()` on page | Tree reads (draft and versioned) |
| `canEdit()` on parent | Create, reorder (target parent) |
| `canCreate()` on element | Create, duplicate |
| `canEdit()` on element | Reorder |
| `canEdit()` on source parent | Cross-parent reorder |
| `canDelete()` on element | Delete |
| `canPublish()` / `canUnpublish()` | Publish / unpublish |

Permission resolution delegates to the owning page: `GridElement.canEdit()` walks the parent chain to the nearest `SiteTree` and calls `canEdit()` on it. Orphaned elements (no page in chain) fall back to `CMS_ACCESS` permission.

### Client Configuration

`getClientConfig()` exposes the grid adapter configuration to the frontend via SilverStripe's admin client config mechanism:

```php
[
    'viewports'        => [['key' => 'xs', 'label' => 'Extra small'], ...],
    'defaultViewport'  => 'md',
    'columnCount'      => 12,
    'rowClasses'       => 'row',
    'baseWidthClasses' => {'1': 'col-1', '2': 'col-2', ...},
    'baseOffsetClasses'=> {'0': 'offset-0', '1': 'offset-1', ...},
]
```

This allows the frontend grid editor to render column width previews and viewport controls without knowing the concrete CSS framework.

## Service Layer

### RequestBodyParser

Turns raw JSON arrays from incoming `HTTPRequest` bodies into typed, validated request DTOs (`CreateElementRequest`, `CreateContentRequest`, `ReorderRequest`, `DuplicateToRequest`, `UpdateGridSettingsRequest`, `ResetGridSettingsOverridesRequest`). Each `parseX()` method returns a `Result` — invalid payloads fail with a `ValidationError` carrying a specific field name, which the controller translates to HTTP 400. The parser is injected with `GridAdapterInterface` so that viewport-scoped request fields can be validated against the configured viewport set.

### GridElementService

Domain service for element creation and duplication lifecycle (`createElement`, `createContentElement`, `duplicateElement`, `duplicateElementTo`). Mirrors `ReorderService`'s contract: receives already-loaded, already-authorized objects and returns a `Result<GridElement>`. All writes go through `WriteResult::from()` so that thrown `ValidationException`s surface as `Result::fail()` failures. Cross-page duplication (`duplicateElementTo`) additionally validates ownership (C1) and hierarchy (C2) before writing.

### GridSettingsService

Domain service for column `GridSettings` mutations. `updateSettings()` applies viewport-scoped width/offset/visibility changes — writes to the default config when the targeted viewport matches the adapter default, otherwise to an override, and automatically drops overrides that collapse back to the default (redundant-override cleanup). `resetOverrides()` clears every column's viewport overrides across a page/zone (optionally scoped to a single viewport) in a single pass. Depends on `GridAdapterInterface` (to identify the default viewport) and `GridTreeBuilder` (to walk the page's columns for bulk reset).

### GridTreeBuilder

Builds the full element tree for a page using breadth-first batch loading — one query per hierarchy depth level.

```
buildForPage(page, zone)
  │
  ├─ loadAllElements()
  │    ├─ Level 0: query Sections by page (zone-filtered)
  │    ├─ Level 1: query Rows by all Section IDs
  │    ├─ Level 2: query Columns by all Row IDs
  │    └─ Level 3: query content elements by all Column IDs
  │
  └─ assembleSubTree()
       └─ Recursive in-memory assembly from pre-loaded data
```

Elements are keyed by the composite `"ParentClass:ParentID"` string in the lookup map. This prevents false matches when a page ID coincides with an element ID.

Each element is converted to a `GridNode` DTO — a readonly value object that carries base fields (id, parentId, title, blockSchema, version, permissions, status) and optional container fields (containerType, allowedTypes, children). Column nodes additionally carry `gridSettings`. The `status` field is a precomputed `ElementStatus` enum (`draft | published | modified | removed`) derived from SilverStripe's `getStatusFlags()` output, so consumers don't re-derive presentation state from the raw flag map. The `GridNode` implements `JsonSerializable` with conditional field inclusion: leaf nodes omit container fields from the serialized output.

The builder provides an `updateElementData` extension point, allowing other modules to inject additional data into each node's `extensions` array.

### ReorderService

Orchestrates element reordering through three phases:

```
ReorderService.reorder(element, targetParent, afterElementId)
  │
  ├─ Validate (ReorderValidator)
  │    └─ Hierarchy rule check (cross-parent only)
  │
  ├─ Compute sort order (in-memory)
  │    └─ Load siblings, splice, reindex
  │
  └─ Persist dirty elements
       └─ WriteResult catches ValidationException → Result
```

Each step returns a `Result`. If any step fails, the service short-circuits and the failure propagates to the controller.

### Sort Computation

`ReorderService` computes new Sort values entirely in memory:

1. Load target siblings (zone-filtered for Sections)
2. Exclude the moved element from the sibling list
3. Resolve insertion index from `afterElementId` (`null` means first position)
4. `array_splice()` the element into position
5. Reindex Sort values (1-based: 1, 2, 3, ...)
6. Track dirty elements (only those whose `Sort` or `ParentID` actually changed)

For cross-parent moves, the source parent's siblings are also reindexed to close the gap left by the moved element. The moved element is marked always-dirty even if its Sort value happens to stay the same, because its `ParentID` has changed.

### WriteResult

The boundary where SilverStripe's `ValidationException` becomes a domain `Result`. Used by `ReorderService` to persist dirty elements and by other write operations throughout the codebase.

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
2. Parent is `SiteTree` → check `can_be_root` on the element
3. Parent is container → check `isElementAllowed()` against config

Violations throw `ValidationException`, preventing the database write.

### Reorder-Time: ReorderValidator

Called by `ReorderService` before executing a cross-parent move. Applies the same hierarchy rules but returns `Result::fail()` instead of throwing, maintaining the Result pattern contract.

Same-parent moves skip validation entirely — reordering within a container cannot violate hierarchy rules.

### ElementAllowanceTrait

Shared logic for checking whether an element class is permitted by a parent's `allowed_elements` / `disallowed_elements` config. Respects the `stop_element_inheritance` flag to prevent config inheritance up the class hierarchy. Used by both `HierarchyValidationService` and `ReorderValidator`.

## Repository Layer

`GridElementRepositoryInterface` provides three query methods:

| Method | Purpose |
|--------|---------|
| `findById(int)` | Single element lookup |
| `findByParentIds(list<int>)` | Elements by ParentID (simple filter) |
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

### Interface Contract (14 methods)

| Method | Returns | Purpose |
|--------|---------|---------|
| `getViewports()` | `list<Viewport>` | Active viewport breakpoints |
| `getColumnCount()` | `positive-int` | Total grid columns |
| `getDefaultViewport()` | `Viewport` | Default/base viewport |
| `getWidthClass(viewport, width)` | `string` | Width class for viewport |
| `getOffsetClass(viewport, offset)` | `string` | Offset class for viewport |
| `getBaseWidthClass(width)` | `string` | Width class for base viewport |
| `getBaseOffsetClass(offset)` | `string` | Offset class for base viewport |
| `getVisibilityClasses(viewport)` | `list<string>` | Hide/restore class pair |
| `getRowClasses()` | `string` | Row container classes |
| `getContainerClass(fluid)` | `string` | Container wrapper classes |
| `getTitleClassOptions()` | `array<string, string>` | CSS class to label mapping |
| `getOffsetStrategy()` | `OffsetStrategy` | Margin-based vs grid-placement |
| `getContainerMaxWidth()` | `positive-int` | Max container width in px (for responsive images) |
| `getColumnPixelWidth(columnSpan)` | `positive-int` | Pixel width of a column span at max container width (for responsive images) |

### Config-Driven Base Class

`GridAdapter` is the single concrete base class implementing both `GridAdapterInterface` and `ContentLayoutAdapterInterface`. All CSS class generation is driven by Configurable static properties — format strings, class maps, and scalar values. Framework presets (BootstrapAdapter, TailwindAdapter, BulmaAdapter) are zero-method subclasses that only override static properties.

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

The default adapter is Bootstrap, bound via YAML DI:

```yaml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\GridAdapterInterface:
    class: WeDevelop\Grid\Adapter\BootstrapAdapter
```

## Content Layout System

The content layout system adds media (image/video) capability with side-by-side layout to content elements. It complements the grid adapter system: grid handles column widths and offsets, content layout handles aspect ratios, ordering, alignment, and directional padding.

### Architecture

```
BlockMediaExtension (applied to ContentElement via YAML)
  └── ContentLayoutAdapterInterface (8 methods)
        └── GridAdapter (same instance as GridAdapterInterface)
```

Content layout is implemented directly by `GridAdapter` — the same adapter instance serves both `GridAdapterInterface` and `ContentLayoutAdapterInterface`. Content layout CSS strings are Configurable statics on the adapter alongside grid CSS strings. No separate adapter or data bag needed.

### ContentLayoutAdapterInterface (8 methods)

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

Applied to `ContentElement` by default via YAML (`_config/content-layout.yml`). Adds media attachment and layout controls to any `GridElement`.

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

```yaml
# _config/content-layout.yml
SilverStripe\Core\Injector\Injector:
  WeDevelop\Grid\Contract\ContentLayoutAdapterInterface:
    factory: WeDevelop\Grid\Factory\GridAdapterFactory

WeDevelop\Grid\Model\ContentElement:
  extensions:
    BlockMedia: WeDevelop\Grid\Extensions\BlockMediaExtension
```

`GridAdapterFactory` resolves `GridAdapterInterface` from the Injector and returns the same singleton, so both interfaces share one adapter instance. The factory pattern is used instead of a `%$` alias because it guarantees the singleton is fully constructed before being returned.

## Value Objects

| Class | Purpose |
|-------|---------|
| `ContainerType` | Enum: Section, Row, Column |
| `Viewport` | `final readonly class` with `key` and `label` |
| `GridNode` | Readonly DTO for serialized tree nodes |
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
WeDevelop\Grid\Service\ReorderService:
  constructor:
    validator: '%$WeDevelop\Grid\Contract\ReorderValidatorInterface'
    elementRepository: '%$WeDevelop\Grid\Repository\GridElementRepositoryInterface'
```

## CMS Integration

`GridPageExtension` (applied to `SiteTree` via YAML) provides the integration point:

- Declares `has_many` to Section (with `owns`, `cascade_deletes`, `cascade_duplicates`)
- Removes the default `Content` field from the CMS form
- Injects `GridEditorField` as the React mount point for the grid editor

`GridEditorField` is a lightweight `FormField` subclass that renders data attributes (`pageId`, `zone`) and delegates all mutations to the API controller. Its `saveInto()` is a no-op — the grid editor manages persistence through the JSON API, not through the CMS form save cycle.

## Extension Points

| Hook | Location | Purpose |
|------|----------|---------|
| `updateContainerClasses` | Section | Modify container CSS classes |
| `updateColumnClasses` | Column | Modify column CSS classes |
| `updateElementData` | GridTreeBuilder | Inject extra data into tree nodes |
| `extendedCan` | GridElement | Override permission checks |
| `updateValidate` | HierarchyValidationExtension | Intercept validation lifecycle |
| `updateCMSFields` | BlockMediaExtension | Inject media/layout fields into CMS form |
