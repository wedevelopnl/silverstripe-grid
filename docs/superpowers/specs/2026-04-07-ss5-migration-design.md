# SS5 → SS6 Grid Migration Design

## Problem

The old `wedevelopnl/silverstripe-elemental-grid` (SS5) module uses a fundamentally different data model than the new `wedevelopnl/silverstripe-grid` (SS6) module. Sites upgrading to SS6 need an automated migration path that transforms the old flat element list with pseudo rows and per-element grid settings into the new Section > Row > Column hierarchy.

### Old Module Data Model

- **Flat element list**: All elements live under an `ElementalArea` via `BaseElement.ParentID` → `ElementalArea.ID`. No parent-child nesting.
- **Per-element grid settings**: `BaseElementExtension` adds 15 DB fields to every `BaseElement`: `Size{XS..XL}` (Int), `Offset{XS..XL}` (Int), `Visibility{XS..XL}` (Varchar). Five hardcoded viewports, one column per viewport per property.
- **Pseudo rows**: `ElementRow` is a real `BaseElement` subclass that acts as a delimiter in the flat list. At render time, `ElementalAreaExtension::ElementControllersWithRows()` walks the list and injects virtual (unsaved) `ElementRow` objects at the boundaries when the first/last elements aren't rows. Elements between two `ElementRow` markers are implicitly grouped into the same visual row.
- **ElementRow fields**: `IsFluid` (Boolean), `CustomSectionClass` (Varchar) — these are section-level concerns rendered via the `ElementRow.ss` template which closes/opens `<section>` tags.
- **ElementContent media**: `ElementContentExtension` adds ~15 fields for image/video media with side-by-side layout. Stores CSS class strings for vertical alignment and media position.
- **Page toggle**: `ElementalPageExtension` adds `UseElementalGrid` (Boolean) to pages.

### New Module Data Model

- **Explicit hierarchy**: Section → Row → Column → Content. All real DB records with polymorphic `ParentID + ParentClass`.
- **Grid settings on Column only**: `DBGridSettings` composite field (JSON) with `default` + viewport `overrides`. Viewports are adapter-driven, not hardcoded.
- **ContentElement + BlockMediaExtension**: Replaces `ElementContent` + `ElementContentExtension`. Uses enum values instead of CSS class strings. Renamed fields.
- **Zone-scoped Sections**: Sections carry a `Zone` field scoping them within a page.

## Solution

Two separate BuildTasks (not a mode flag) with shared infrastructure via composition:

1. **`MigrateRowsToSectionsTask`** — each `ElementRow` becomes a Section + Row. For sites where old rows were semantically sections.
2. **`MigrateRowsToSingleSectionTask`** — all rows under a single Section. For sites where old rows were truly rows within one section.

The user chooses whichever task matches their site's usage of the old module.

### Prerequisites

- The old `silverstripe-elemental-grid` module code is uninstalled but its database tables still exist.
- The new `silverstripe-grid` module is installed with the correct `GridAdapterInterface` configured.
- All project-specific content element classes have been updated to extend `GridElement`.

### Required Arguments (both tasks)

| Argument | Purpose |
|---|---|
| `default_viewport` | The old module's configured default viewport (e.g. `MD`). Not available from config since the old module is uninstalled. |
| `zone` | Target zone for new Sections (e.g. `main`). |

### Optional Arguments

| Argument | Purpose |
|---|---|
| `dry-run` | Flag. Log what would be migrated without writing. |
| `viewport-map` | Comma-separated `old=new` pairs (e.g. `XS=xs,SM=sm,MD=md,LG=lg,XL=xl`). Maps old viewport keys to new adapter keys. If omitted, derived automatically by case-insensitive matching of old uppercase keys against the active adapter's viewport definitions. Required when migrating across CSS frameworks (e.g. Bootstrap → Tailwind) where viewport names differ. |
| `page-ids` | Comma-separated list of page IDs to migrate (e.g. `1,5,12`). If omitted, all eligible pages are migrated. Useful for testing the migration on specific pages before running the full batch. |

## Architecture

```
BuildTask (thin shell)                    BuildTask (thin shell)
MigrateRowsToSectionsTask                 MigrateRowsToSingleSectionTask
        │                                          │
        └──────────┐                ┌──────────────┘
                   ▼                ▼
              GridMigrationService
              (orchestration: per-page loop, stages, transactions, dry-run)
                   │
        ┌──────────┼──────────────┐
        ▼          ▼              ▼
RowMappingStrategy  LegacyDataReader  FieldMapper
(interface)         (raw SQL reads)   (grid settings + media conversion)
   │
   ├── RowPerSectionStrategy    — each ElementRow → Section + Row
   └── AllRowsInSectionStrategy — all rows → Rows in one Section
```

## Components

### LegacyDataReader

Encapsulates all raw SQL queries against old tables. Returns plain DTOs. No ORM classes exist for the old schema.

**Key methods:**

| Method | Returns | Source tables |
|---|---|---|
| `getEligiblePages()` | `list<array{pageId: int, areaId: int}>` | `SiteTree` (or `SiteTree_Live`) — pages where `UseElementalGrid = 1` and `ElementalAreaID > 0`. Returns both page ID and ElementalArea ID. |
| `getElementsForArea(int $areaId)` | `list<LegacyElement>` | `BaseElement` (or `_Live`) — all elements in that area, ordered by `Sort ASC` |
| `getRowData(int $elementId)` | `?LegacyRowData` | `ElementRow` (or `_Live`) — `IsFluid`, `CustomSectionClass` |
| `getContentMediaData(int $elementId)` | `?LegacyMediaData` | `ElementContent` (or `_Live`) — all media/layout fields |

**Stage handling**: Takes a `stage` parameter and appends `_Live` to table names when reading live data. This is the only place stage awareness for reads lives.

**Extension point**: After reading elements for an area, invokes `updateLegacyElements` so site developers can:
- Enrich elements with custom subclass data from tables we don't know about
- Filter out elements to prevent migration
- Fix up edge cases

**Subclass table joins**: Only joins known tables (`ElementRow`, `ElementContent`). Custom element subclass tables are the project's responsibility via the extension hook.

**Old table hierarchy**: All reads target the old `dnadesign/silverstripe-elemental` table hierarchy: `BaseElement` (shared fields + grid extension fields), `ElementRow` (row-specific fields), `ElementContent` (HTML + media extension fields). These tables remain in the database after the old module is uninstalled. The reader appends `_Live` to table names for live-stage reads (e.g. `BaseElement_Live`).

### FieldMapper

Pure stateless transformations. No DB access, no side effects.

**Grid settings conversion**: Takes the 15 flat fields + `default_viewport` argument + viewport key map and produces `GridSettings` JSON:

1. Fields matching `default_viewport` (e.g. `SizeMD`, `OffsetMD`, `VisibilityMD`) become `default`
2. Other viewports become `overrides` only when they differ from the default
3. `Size = 0` means "not set" in the old module — skipped, not stored as override
4. Visibility: `'visible'` → `true`, `'hidden'` → `false`, empty string → not set (skipped)

Example — old data with `default_viewport = MD`:
```
SizeXS=0, SizeSM=0, SizeMD=8, SizeLG=0, SizeXL=12
OffsetXS=0, OffsetSM=0, OffsetMD=2, OffsetLG=0, OffsetXL=0
VisibilityXS=hidden, VisibilitySM=, VisibilityMD=visible, VisibilityLG=, VisibilityXL=visible
```

Produces:
```json
{
  "default": { "width": 8, "offset": 2, "visible": true },
  "overrides": {
    "xs": { "visible": false },
    "xl": { "width": 12 }
  }
}
```

**Viewport key map**: Old module uses uppercase keys (`XS`, `SM`, `MD`, `LG`, `XL`). New module's adapter defines its own keys (Bootstrap lowercase `xs`, `sm`, etc.). The mapper receives a key map as input. When the `viewport-map` argument is provided, it's used directly. Otherwise, derived automatically by case-insensitive matching of old keys against the active adapter's viewport definitions. No hardcoded assumption that old keys are just uppercase versions of new keys.

**Media field mapping** — hardcoded lookup tables:

```php
// ContentVerticalAlign (CSS class → enum value)
'' => 'top',
'align-items-center' => 'center',
'align-items-end' => 'bottom',

// MediaPosition (CSS class → enum value)
'order-1' => 'first',
'order-2' => 'last',
'order-1 order-md-2' => 'last-on-desktop',

// Field renames (old → new)
'MediaVideoFullURL' => 'VideoURL',
'MediaVideoProvider' => 'VideoProvider',
'MediaVideoHasOverlay' => 'VideoHasOverlay',
'MediaVideoCustomThumbnailID' => 'VideoCustomThumbnailID',
'MediaVideoEmbeddedName' => 'VideoEmbedName',
'MediaVideoEmbeddedURL' => 'VideoEmbedURL',
'MediaVideoEmbeddedDescription' => 'VideoEmbedDescription',
'MediaVideoEmbeddedThumbnail' => 'VideoEmbedThumbnail',
'MediaVideoEmbeddedCreated' => 'VideoEmbedCreated',
'ContentVerticalAlign' => 'VerticalAlignment',  // + CSS class → enum value conversion

// ContentColumns (Varchar → Int, empty string → 0)
// Straight cast, '' maps to 0 (no side-by-side layout)

// MediaRatio (empty string → 'auto', other values pass through unchanged)
// Old: '', '1x1', '4x3', '16x9'
// New: 'auto', '1x1', '4x3', '16x9'

// MediaType — values are identical between old and new ('image', 'video')
// No mapping needed, pass through unchanged

// MediaImageID — same column name in both modules, pass through unchanged

// ExtraColumnGap → GapSize (different value scales, hardcoded mapping)
2 => 1,   // Smallest → Smallest
3 => 1,   // Smaller → Smallest
5 => 2,   // Small → Small
7 => 3,   // Normal → Medium
9 => 3,   // Medium → Medium
11 => 4,  // Large → Large
16 => 5,  // Larger → Largest
17 => 5,  // Largest → Largest
0 => 0,   // None → None
```

**ClassName resolution**: Default mapping: `DNADesign\Elemental\Models\ElementContent` → `WeDevelop\Grid\Model\ContentElement`. Unknown classes pass through unchanged.

**NULL and empty string handling**: The FieldMapper treats both `NULL` and `''` (empty string) as "not set" for all optional fields (visibility, MediaPosition, MediaRatio, ContentColumns). This covers elements that were never edited and retained database defaults.

**Default MediaPosition**: When `MediaPosition` is `NULL` or `''` in legacy data, maps to `'first'` (the new module's default), matching the old module's default of `'order-1'`.

**Bootstrap assumption**: The hardcoded media field mappings (ContentVerticalAlign CSS classes, MediaPosition CSS classes) assume the old site used Bootstrap. Projects that used Tailwind or Bulma with the old module must override these mappings via the `updateFieldMapping()` extension hook, since the old module stored framework-specific CSS class strings for `MediaPosition` values containing responsive breakpoint classes (e.g. `order-md-2` is Bootstrap-specific).

**Extension points**:
- `updateElementFieldMapping($mappedFields, $legacyElement)` — per-element field mapping. Projects can add fields from custom extensions, override default mappings.
- `updateClassNameMapping(&$newClassName, $oldClassName)` — per-element class name resolution. Projects can declare their old→new FQCN mappings.
- `updateFieldMapping()` — global hook for projects to adjust lookup tables for custom CSS frameworks.

### RowMappingStrategy (interface)

```php
interface RowMappingStrategy
{
    /**
     * @param list<LegacyElement> $elements Flat sorted element list
     * @param int $pageId Target page ID for parent relationships
     * @param string $zone Target zone
     * @return list<MigrationSection> Hierarchy to create
     */
    public function buildHierarchy(array $elements, int $pageId, string $zone): array;
}
```

### ElementGrouper (shared utility)

Both strategies share the same logic for splitting a flat element list on `ElementRow` boundaries:

1. Walk the sorted list
2. When an `ElementRow` is encountered, close the current group and start a new one
3. Elements before the first `ElementRow` form an implicit boundary group
4. Elements after the last `ElementRow` form an implicit boundary group
5. An `ElementRow` followed immediately by another `ElementRow` produces an empty group (preserved — we do not drop data)

Returns raw groups that the strategies then map to `MigrationSection`/`MigrationRow`.

### RowPerSectionStrategy

Each group from `ElementGrouper` becomes a `MigrationSection` with one `MigrationRow`:

- `ElementRow.Title` → Row title
- `ElementRow.IsFluid` → Section `fluid_container`
- `ElementRow.CustomSectionClass` → Section ExtraClass
- `ElementRow.ExtraClass` → Row ExtraClass
- Implicit boundary groups (no `ElementRow`) get Section + Row with default values
- Each content element in the group → one `MigrationColumn`

### AllRowsInSectionStrategy

All groups from `ElementGrouper` become `MigrationRow` entries under a single `MigrationSection`:

- First `ElementRow`'s `IsFluid` → Section `fluid_container`
- First `ElementRow`'s `CustomSectionClass` → Section ExtraClass
- Later `ElementRow` records with different `IsFluid` or `CustomSectionClass` → log warning, discard values
- Each `ElementRow`'s `Title` and `ExtraClass` → its corresponding Row
- Each content element in each group → one `MigrationColumn`

### GridMigrationService

Orchestrates the full migration. Receives strategy, reader, and mapper via constructor.

**Flow per page:**

**Table hierarchy change**: The old module stores content elements in the `BaseElement` table (from `dnadesign/silverstripe-elemental`). The new module uses a `GridElement` table. These are different physical tables. After the PHP classes are updated to extend `GridElement` and `dev/build` runs, the `GridElement` table exists but is empty — data remains in `BaseElement`. The migration must **INSERT new records** into the `GridElement` table hierarchy, not UPDATE existing rows in `BaseElement`.

**New IDs for all migrated elements**: Content elements receive new IDs when inserted into `GridElement`. The migration maintains an **old ID → new ID mapping** per page to reconcile draft and live stages. Old records in `BaseElement` are left as orphans (same as `ElementRow` records). External references to old element IDs (e.g. shortcodes, links) will break — this is an accepted consequence of the table hierarchy change.

SilverStripe's Versioned extension expects the same record ID to exist in both draft and `_Live` tables when content is published. Therefore, the migration writes on **draft first** (getting new IDs), then publishes to live for elements that also existed on live.

```
for each eligible page:
    1. Idempotency check: query Section table (draft) for this page + zone
       → if Sections exist, skip (log "already migrated")

    2. Read DRAFT legacy elements for this page's ElementalAreaID
       → if empty, skip to step 6 (live-only elements)

    3. Run FieldMapper on each element's grid fields + media fields

    4. Pass to RowMappingStrategy → get list<MigrationSection>

    5. If dry-run: log what would be created, continue to next page

    6. Begin transaction
       a. Write Sections → Rows → Columns on DRAFT via ORM (gets new IDs)
       b. For each content element: create new GridElement record on DRAFT
          with mapped fields, ParentID → new Column ID,
          ParentClass → Column::class, ClassName from FieldMapper
       c. For subclass data (e.g. ContentElement with BlockMediaExtension
          fields): write mapped fields to the new subclass tables
       d. Build mappings: old element ID → new element ID,
          old element ID → new Column ID

    7. Read LIVE legacy elements for this page's ElementalAreaID
       → for each live element, find its matching new element/Column
         from the draft mapping (by old element ID)
       → publish containers to live via writeToStage(Versioned::LIVE)
       → publish content elements to live via writeToStage(Versioned::LIVE)
       → live-only elements (no draft counterpart) get new records
         written to both draft and live to maintain Versioned integrity

    8. Commit (or rollback on failure, log error, continue to next page)
```

**Live-only elements**: Elements that exist on live but were deleted on draft are an edge case. These still need containers and content records. The migration creates Section/Row/Column + content element records on both stages (the records exist on draft even though the old content was draft-deleted) to maintain the Versioned contract. This means some draft-deleted content will "reappear" on draft — an accepted trade-off, as the alternative (live-only records with no draft counterpart) breaks Versioned's assumptions.

**Subclass table migration**: Content element data spans multiple tables in both old and new hierarchies. For example, old `ElementContent` has data in `BaseElement` (shared fields) + `ElementContent` (HTML field) + extension columns on `ElementContent` (media fields from `ElementContentExtension`). The new `ContentElement` has data in `GridElement` (shared fields) + `ContentElement` (HTML field) + extension columns (media fields from `BlockMediaExtension`). The migration must read from the old table hierarchy and write to the new one. The `FieldMapper` handles the column-level mapping; the `GridMigrationService` handles writing to the correct new tables via ORM.

**ORM writes with auto-scaffolding disabled**: The migration uses ORM `write()` calls so that SilverStripe's Versioned extension handles `_Versions` entries and stage-specific table writes automatically. To prevent Section and Row `onAfterWrite` hooks from auto-creating child records (which would duplicate the migration-created hierarchy), the migration temporarily disables auto-scaffolding via config before writing:

```php
Section::config()->set('auto_scaffold', false);
Row::config()->set('auto_scaffold', false);
// ... write Sections, Rows, Columns ...
// Config resets automatically after request (or restore explicitly in finally block)
```

**Stage-specific writes**: Draft containers are written via normal ORM `write()` on `Versioned::DRAFT`. Live publishing uses `writeToStage(Versioned::LIVE)` on the same records (same IDs), so both stages share record IDs — preserving the Versioned contract. ORM + Versioned handles `_Versions` entries automatically.

**Sort auto-assignment**: `GridElement::onBeforeWrite()` auto-assigns Sort when it's `0`. The migration must set explicit Sort values on containers **before** calling `write()` to prevent the auto-sort hook from overriding intended ordering. The Sort values come from the `MigrationSection`/`MigrationRow`/`MigrationColumn` DTOs which derive them from the old element ordering.

**Content element creation**: Unlike containers (which are purely new records), content elements are **copies** of old data into the new table hierarchy. The ORM creates new records in `GridElement` + subclass tables with mapped field values. The old `BaseElement` records are not modified — they remain as orphans in the legacy tables.

**Old `ElementRow` records**: Left as orphans in the legacy tables after migration. The old tables are dead weight post-migration and cleaning them up is not worth the complexity. SilverStripe does not provide a clean mechanism for removing legacy table data.

**New fields without old equivalents**: The new `GridElement.Style` field has no counterpart in the old module. It defaults to empty string and is not part of the migration.

**`UseElementalGrid` table location**: The `UseElementalGrid` and `ElementalAreaID` columns may live on the `Page` table rather than `SiteTree`, depending on which class the old module's extension was applied to. The `LegacyDataReader` must query the correct table — verify against the actual database schema. A JOIN across `SiteTree` and `Page` is the safest approach.

**`ElementRow` TitleTag/TitleClass**: The old `ElementRow` inherits `TitleTag`, `TitleClass`, and `ShowTitle` from `BaseElementExtension` but removes them from the CMS UI. These fields likely contain default/empty values in the database. They are dropped during migration — only `Title` and `ExtraClass` are carried to the new Row.

### DTOs

```
LegacyElement
  - id: int
  - className: string (FQCN from old BaseElement.ClassName)
  - title: string
  - showTitle: bool
  - titleTag: string
  - titleClass: string
  - sort: int
  - extraClass: string
  - isRow: bool (className === 'WeDevelop\ElementalGrid\Models\ElementRow')
  - gridFields: array (SizeXS..XL, OffsetXS..XL, VisibilityXS..XL)

LegacyRowData
  - isFluid: bool
  - customSectionClass: string

LegacyMediaData
  - all ElementContentExtension fields as key-value pairs

MigrationSection
  - title: string
  - zone: string
  - isFluid: bool
  - extraClass: string
  - sort: int
  - rows: list<MigrationRow>

MigrationRow
  - title: string
  - extraClass: string
  - sort: int
  - columns: list<MigrationColumn>

MigrationColumn
  - gridSettings: GridSettings (converted by FieldMapper)
  - sort: int
  - element: LegacyElement (the content element to re-parent)
```

### BuildTask Shells

**`MigrateRowsToSectionsTask`**:
- Segment: `migrate-grid-rows-to-sections`
- Wires `RowPerSectionStrategy` → `GridMigrationService` → `run()`

**`MigrateRowsToSingleSectionTask`**:
- Segment: `migrate-grid-rows-to-single-section`
- Wires `AllRowsInSectionStrategy` → `GridMigrationService` → `run()`

Both tasks:
- Validate required arguments (`default_viewport`, `zone`), exit early with usage instructions if missing
- Parse `viewport-map` if provided; otherwise derive automatically by case-insensitive matching of old uppercase viewport keys (`XS`, `SM`, `MD`, `LG`, `XL`) against the active `GridAdapterInterface`'s viewport definitions
- Output progress: page count, per-page results, warnings, summary

## File Layout

```
src/Migration/
  Task/
    MigrateRowsToSectionsTask.php
    MigrateRowsToSingleSectionTask.php
  Service/
    GridMigrationService.php
    LegacyDataReader.php
    FieldMapper.php
    ElementGrouper.php
  Strategy/
    RowMappingStrategy.php          (interface)
    RowPerSectionStrategy.php
    AllRowsInSectionStrategy.php
  DTO/
    LegacyElement.php
    LegacyRowData.php
    LegacyMediaData.php
    MigrationSection.php
    MigrationRow.php
    MigrationColumn.php
```

## Testing Strategy

### Unit Tests (no DB, no framework)

| Class | What to test |
|---|---|
| `FieldMapper` | Grid settings conversion: default viewport extraction, override detection, zero-value skipping, all viewport combinations |
| `FieldMapper` | Visibility mapping: `'visible'`→`true`, `'hidden'`→`false`, empty string→skipped |
| `FieldMapper` | Media field mapping: CSS class→enum lookups, field renames, unknown values |
| `FieldMapper` | ClassName resolution: `ElementContent→ContentElement`, unknown classes pass through |
| `FieldMapper` | Viewport key map applied correctly (uppercase→lowercase conversion) |
| `ElementGrouper` | Explicit rows: split correctly on boundaries |
| `ElementGrouper` | Implicit boundary groups: elements before first row, after last row |
| `ElementGrouper` | No rows at all: all elements in one implicit group |
| `ElementGrouper` | Adjacent rows: empty group preserved |
| `ElementGrouper` | Single element, empty list |
| `RowPerSectionStrategy` | Each group → Section + Row, field placement (IsFluid→Section, CustomSectionClass→Section, Title→Row, ExtraClass→Row) |
| `RowPerSectionStrategy` | Implicit groups get default Section + Row values |
| `AllRowsInSectionStrategy` | Single Section created, first row's config used |
| `AllRowsInSectionStrategy` | Later rows with different IsFluid/CustomSectionClass → warning logged, values discarded |

### Integration Tests (full SilverStripe env, real DB)

| Category | What to test |
|---|---|
| **Core migration** | End-to-end: seed old tables → run migration → verify full Section/Row/Column hierarchy with correct parent relationships |
| **Core migration** | Grid settings correctly converted per element (default viewport, overrides, zero-value skipping) |
| **Core migration** | Media fields mapped correctly on ContentElement (renames, CSS class→enum, has_one relations preserved) |
| **Core migration** | Element Sort order preserved through the hierarchy |
| **Core migration** | Title, ShowTitle, TitleTag, TitleClass, ExtraClass carried over to new elements |
| **Table migration** | Content element data correctly inserted into `GridElement` + subclass tables (not left in `BaseElement`) |
| **Table migration** | Content elements receive new IDs (not reusing old `BaseElement` IDs) |
| **Table migration** | Old `BaseElement` records left untouched as orphans |
| **Table migration** | Subclass data migrated correctly (e.g. `ElementContent.HTML` → `ContentElement.HTML`) |
| **Table migration** | `has_one` relation IDs preserved (MediaImageID, VideoCustomThumbnailID renamed correctly) |
| **Stage handling** | Draft-only element: new record exists only in draft tables |
| **Stage handling** | Live-only element (deleted on draft): new record exists on both draft and live (Versioned integrity) |
| **Stage handling** | Element with different content on draft vs live — both versions migrated with same new ID |
| **Stage handling** | Draft and live containers share the same IDs (Versioned contract) |
| **Stage handling** | `_Versions` records created for both stages |
| **ID mapping** | Old element ID → new element ID mapping used correctly for live-stage reconciliation |
| **ID mapping** | Old element ID → new Column ID mapping assigns correct parents |
| **Idempotency** | Run twice on same page — no duplicates, second run skips |
| **Idempotency** | Partially migrated state (draft done, live not yet) — completes live without touching draft |
| **Dry-run** | No writes to any table, output describes what would happen |
| **Transaction safety** | Failure mid-page rolls back that page cleanly, other pages unaffected |
| **Pseudo rows** | Elements before first explicit row get implicit Section+Row |
| **Pseudo rows** | Elements after last explicit row get implicit Section+Row |
| **Pseudo rows** | Page with no explicit rows at all — all elements wrapped in implicit Section+Row |
| **Row per section** | ElementRow field mapping: IsFluid→Section, CustomSectionClass→Section ExtraClass, Title→Row, ExtraClass→Row |
| **Single section** | First row's IsFluid and CustomSectionClass applied to Section |
| **Single section** | Later row with different IsFluid/CustomSectionClass — warning logged, values discarded |
| **Extension hooks** | `updateLegacyElements`: filter out an element → verify it's not migrated |
| **Extension hooks** | `updateLegacyElements`: enrich element with custom subclass data → verify data arrives in new record |
| **Extension hooks** | `updateElementFieldMapping`: add custom field mapping → verify field written to new table |
| **Extension hooks** | `updateElementFieldMapping`: override default mapping → verify override takes effect |
| **Extension hooks** | `updateClassNameMapping`: provide custom old→new FQCN → verify ClassName updated |
| **Custom elements** | Element with project-specific extension adding DB fields — extension hook migrates those fields correctly |
| **Custom elements** | Multiple different element types in one page — each gets correct ClassName and field mapping |
| **Edge cases** | Page with `UseElementalGrid = false` — skipped entirely |
| **Edge cases** | Page with empty ElementalArea — skipped |
| **Edge cases** | Adjacent ElementRows with no content between them — empty Row created |
