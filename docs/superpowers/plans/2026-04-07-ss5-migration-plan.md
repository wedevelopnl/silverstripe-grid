# SS5 → SS6 Grid Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two BuildTasks that migrate old elemental-grid data (flat element list with pseudo rows) into the new Section > Row > Column hierarchy.

**Architecture:** Composition-based — two thin BuildTask shells share a `GridMigrationService` orchestrator, `LegacyDataReader` (raw SQL), `FieldMapper` (pure transformations), and a `RowMappingStrategy` interface with two implementations. All migration code lives under `src/Migration/`.

**Tech Stack:** PHP 8.3, SilverStripe 6, PHPUnit 11 (unit + integration tests via Docker)

**Spec:** `docs/superpowers/specs/2026-04-07-ss5-migration-design.md`

---

## File Structure

### New files to create

```
src/Migration/
  DTO/
    LegacyElement.php           — Immutable DTO for old BaseElement row data
    LegacyRowData.php           — Immutable DTO for old ElementRow fields
    LegacyMediaData.php         — Immutable DTO for old ElementContent media fields
    MigrationSection.php        — Intermediate hierarchy: section with rows
    MigrationRow.php            — Intermediate hierarchy: row with columns
    MigrationColumn.php         — Intermediate hierarchy: column with legacy element ref
  Service/
    ElementGrouper.php          — Splits flat element list on ElementRow boundaries
    FieldMapper.php             — Pure transformations: grid settings, media fields, class names
    LegacyDataReader.php        — Raw SQL reads from old BaseElement/ElementRow/ElementContent tables
    GridMigrationService.php    — Orchestrator: page loop, stages, transactions, dry-run
  Strategy/
    RowMappingStrategy.php      — Interface: flat elements → MigrationSection hierarchy
    RowPerSectionStrategy.php   — Each ElementRow → Section + Row
    AllRowsInSectionStrategy.php — All rows → Rows in single Section
  Task/
    MigrateRowsToSectionsTask.php       — BuildTask shell
    MigrateRowsToSingleSectionTask.php  — BuildTask shell

tests/Unit/Migration/
  Support/
    LegacyElementFactory.php    — Builds LegacyElement instances with sensible defaults
  Service/
    ElementGrouperTest.php
    FieldMapperTest.php
  Strategy/
    RowPerSectionStrategyTest.php
    AllRowsInSectionStrategyTest.php

tests/Integration/Migration/
  Service/
    LegacyDataReaderTest.php
    GridMigrationServiceTest.php
  Support/
    LegacyTableSeeder.php       — Helper to INSERT rows into old BaseElement/ElementRow/ElementContent tables
```

---

## Task 1: DTOs

**Files:**
- Create: `src/Migration/DTO/LegacyElement.php`
- Create: `src/Migration/DTO/LegacyRowData.php`
- Create: `src/Migration/DTO/LegacyMediaData.php`
- Create: `src/Migration/DTO/MigrationSection.php`
- Create: `src/Migration/DTO/MigrationRow.php`
- Create: `src/Migration/DTO/MigrationColumn.php`

These are pure value objects with no logic — no tests needed. Also create a shared test factory for building `LegacyElement` instances with sensible defaults.

- [ ] **Step 1: Create LegacyElement DTO**

```php
// src/Migration/DTO/LegacyElement.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyElement
{
    /**
     * @param array<string, int> $sizeFields      e.g. ['XS' => 0, 'SM' => 0, 'MD' => 8, ...]
     * @param array<string, int> $offsetFields     e.g. ['XS' => 0, 'SM' => 0, 'MD' => 2, ...]
     * @param array<string, ?string> $visibilityFields e.g. ['XS' => 'hidden', 'MD' => 'visible', ...]
     * @param array<string, mixed> $extraData      extension hook can attach arbitrary data
     */
    public function __construct(
        public int $id,
        public string $className,
        public string $title,
        public bool $showTitle,
        public string $titleTag,
        public string $titleClass,
        public int $sort,
        public string $extraClass,
        public bool $isRow,
        public array $sizeFields,
        public array $offsetFields,
        public array $visibilityFields,
        public ?LegacyRowData $rowData = null,
        public ?LegacyMediaData $mediaData = null,
        public array $extraData = [],
    ) {}
}
```

- [ ] **Step 2: Create LegacyRowData DTO**

```php
// src/Migration/DTO/LegacyRowData.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyRowData
{
    public function __construct(
        public bool $isFluid,
        public string $customSectionClass,
    ) {}
}
```

- [ ] **Step 3: Create LegacyMediaData DTO**

```php
// src/Migration/DTO/LegacyMediaData.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class LegacyMediaData
{
    /** @param array<string, mixed> $fields All ElementContentExtension fields as key-value pairs */
    public function __construct(
        public array $fields,
    ) {}
}
```

- [ ] **Step 4: Create MigrationSection, MigrationRow, MigrationColumn DTOs**

```php
// src/Migration/DTO/MigrationSection.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationSection
{
    /** @param list<MigrationRow> $rows */
    public function __construct(
        public string $title,
        public string $zone,
        public bool $isFluid,
        public string $extraClass,
        public int $sort,
        public array $rows,
    ) {}
}
```

```php
// src/Migration/DTO/MigrationRow.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

final readonly class MigrationRow
{
    /** @param list<MigrationColumn> $columns */
    public function __construct(
        public string $title,
        public string $extraClass,
        public int $sort,
        public array $columns,
    ) {}
}
```

```php
// src/Migration/DTO/MigrationColumn.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\DTO;

use WeDevelop\Grid\Value\GridSettings;

final readonly class MigrationColumn
{
    public function __construct(
        public GridSettings $gridSettings,
        public int $sort,
        public LegacyElement $element,
    ) {}
}
```

- [ ] **Step 5: Create LegacyElementFactory test helper**

Create `tests/Unit/Migration/Support/LegacyElementFactory.php` — a static factory that builds `LegacyElement` instances with sensible defaults (id=1, className='Test\Element', empty grid fields, isRow=false, etc.). Provide named constructors:
- `LegacyElementFactory::content(int $id, int $sort, array $overrides = []): LegacyElement`
- `LegacyElementFactory::row(int $id, int $sort, ?LegacyRowData $rowData = null): LegacyElement`

This avoids boilerplate in the 4+ unit test classes that need `LegacyElement` instances.

- [ ] **Step 6: Commit**

```
git add src/Migration/DTO/ tests/Unit/Migration/Support/
git commit -m "Add migration DTOs and LegacyElement test factory"
```

---

## Task 2: ElementGrouper

**Files:**
- Create: `src/Migration/Service/ElementGrouper.php`
- Create: `tests/Unit/Migration/Service/ElementGrouperTest.php`

**Reference:** Spec section "ElementGrouper (shared utility)" — splits flat element list on ElementRow boundaries.

- [ ] **Step 1: Write failing tests for ElementGrouper**

Test file: `tests/Unit/Migration/Service/ElementGrouperTest.php`

Cover these scenarios:
1. Elements split on explicit row boundaries: `[E1, Row, E2, E3, Row, E4]` → 3 groups
2. Implicit boundary before first row: `[E1, E2, Row, E3]` → 2 groups (first is implicit)
3. Elements after last explicit row: `[Row, E1, E2]` → 1 group (Row's group contains E1, E2)
4. No rows at all: `[E1, E2, E3]` → 1 implicit group
5. Adjacent rows (empty group): `[Row1, Row2, E1]` → Row1 group (empty), Row2 group (E1)
6. Single element, no row: `[E1]` → 1 group
7. Empty list: `[]` → 0 groups
8. Single row, no elements: `[Row]` → 1 empty group

Each group should contain: the `LegacyRowData` (or null for implicit groups) and the list of non-row `LegacyElement`s in that group.

Use the shared `LegacyElementFactory` test helper (created in Task 1) to build `LegacyElement` instances with sensible defaults. Set `isRow: true` for row elements, `isRow: false` for content elements.

- [ ] **Step 2: Run tests to verify they fail**

Run: `make test-unit` (or `php vendor/bin/phpunit --testsuite unit --filter ElementGrouperTest`)
Expected: FAIL — class not found

- [ ] **Step 3: Implement ElementGrouper**

```php
// src/Migration/Service/ElementGrouper.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Service;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\LegacyRowData;

final class ElementGrouper
{
    /**
     * Split a flat sorted element list into groups delimited by ElementRow records.
     *
     * @param list<LegacyElement> $elements Sorted by Sort ASC
     * @return list<array{rowData: ?LegacyRowData, elements: list<LegacyElement>}>
     */
    public function group(array $elements): array
    {
        // Walk the list. Each ElementRow starts a new group.
        // Elements before the first row form an implicit group (rowData: null).
        $groups = [];
        $currentRowData = null;
        $currentElements = [];
        $hasSeenRow = false;

        foreach ($elements as $element) {
            if ($element->isRow) {
                // Close previous group
                if ($hasSeenRow || $currentElements !== []) {
                    $groups[] = ['rowData' => $currentRowData, 'elements' => $currentElements];
                }
                $currentRowData = $element->rowData;
                $currentElements = [];
                $hasSeenRow = true;
            } else {
                $currentElements[] = $element;
            }
        }

        // Close final group (elements after last row, or all elements if no rows)
        if ($hasSeenRow || $currentElements !== []) {
            $groups[] = ['rowData' => $currentRowData, 'elements' => $currentElements];
        }

        return $groups;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test-unit` (or filter to `ElementGrouperTest`)
Expected: PASS

- [ ] **Step 5: Commit**

```
git add src/Migration/Service/ElementGrouper.php tests/Unit/Migration/Service/ElementGrouperTest.php
git commit -m "Add ElementGrouper: splits flat element list on row boundaries"
```

---

## Task 3: FieldMapper

**Files:**
- Create: `src/Migration/Service/FieldMapper.php`
- Create: `tests/Unit/Migration/Service/FieldMapperTest.php`

**Reference:** Spec section "FieldMapper" — grid settings conversion, media field mapping, ClassName resolution.

- [ ] **Step 1: Write failing tests for grid settings conversion**

Test file: `tests/Unit/Migration/Service/FieldMapperTest.php`

Cover:
1. Default viewport extracted correctly (MD fields → default)
2. Non-default viewport with different value → stored as override
3. Size=0 means "not set" → skipped (not stored as override)
4. Visibility: `'visible'` → `true`, `'hidden'` → `false`, empty/null → skipped
5. Viewport key mapping applied (uppercase `XS` → lowercase `xs` in output)
6. All viewports set to same as default → no overrides
7. Full example from spec: `SizeMD=8, OffsetMD=2, SizeXL=12, VisibilityXS=hidden`

The mapper method signature: `mapGridSettings(LegacyElement $element, string $defaultViewport, array $viewportKeyMap): GridSettings`

Use the existing `GridSettings` and `ViewportConfig` value objects from `src/Value/`.

- [ ] **Step 2: Write failing tests for media field mapping**

Cover:
1. ContentVerticalAlign CSS class → enum: `'align-items-center'` → `'center'`, `''` → `'top'`, `'align-items-end'` → `'bottom'`
2. MediaPosition CSS class → enum: `'order-1'` → `'first'`, `'order-2'` → `'last'`, `'order-1 order-md-2'` → `'last-on-desktop'`
3. MediaPosition null/empty → `'first'` (default)
4. Field renames: `MediaVideoFullURL` → `VideoURL`, etc.
5. ExtraColumnGap → GapSize value scale mapping: `7 → 3`, `17 → 5`, `0 → 0`
6. ContentColumns string→int: `'8'` → `8`, `''` → `0`, null → `0`
7. MediaRatio: `''` → `'auto'`, `null` → `'auto'`, `'16x9'` → `'16x9'`
8. NULL vs empty string: both treated as "not set" for all optional fields (visibility, MediaPosition, MediaRatio, ContentColumns)

Method signature: `mapMediaFields(LegacyMediaData $mediaData): array<string, mixed>`

- [ ] **Step 3: Write failing tests for ClassName resolution**

Cover:
1. `DNADesign\Elemental\Models\ElementContent` → `WeDevelop\Grid\Model\ContentElement`
2. Unknown class → pass through unchanged

Method signature: `resolveClassName(string $oldClassName): string`

- [ ] **Step 4: Run all tests to verify they fail**

Run: `make test-unit`
Expected: FAIL — class not found

- [ ] **Step 5: Implement FieldMapper**

`src/Migration/Service/FieldMapper.php` — A **pure class with no framework dependencies** so it remains unit-testable without SilverStripe. No `Extensible` trait.

Lookup tables (field renames, value mappings, class name map) are hardcoded as class constants. For extensibility, the tables can be overridden via constructor injection:

```php
public function __construct(
    ?array $classNameMap = null,
    ?array $verticalAlignMap = null,
    ?array $mediaPositionMap = null,
    ?array $gapSizeMap = null,
) {
    $this->classNameMap = $classNameMap ?? self::DEFAULT_CLASS_NAME_MAP;
    // ... same pattern for other maps
}
```

The extension hooks (`updateElementFieldMapping`, `updateClassNameMapping`, `updateFieldMapping`) live on `GridMigrationService` instead, which already uses the framework. The service calls the hooks and passes results to FieldMapper methods or overrides FieldMapper's constructor args.

Key implementation points:
- `mapGridSettings()`: iterate the 5 old viewports, build `ViewportConfig` for each, compare to default, store as override only when different and not "unset" (size=0)
- `mapMediaFields()`: apply rename map, then value transformation map (CSS class → enum, gap size scale, etc.)
- `resolveClassName()`: check injected class name map, return match or pass through unchanged
- Treat both `NULL` and `''` as "not set" for all optional fields: visibility, MediaPosition, MediaRatio, ContentColumns

- [ ] **Step 6: Run tests to verify they pass**

Run: `make test-unit`
Expected: PASS

- [ ] **Step 7: Commit**

```
git add src/Migration/Service/FieldMapper.php tests/Unit/Migration/Service/FieldMapperTest.php
git commit -m "Add FieldMapper: grid settings, media fields, and class name mapping"
```

---

## Task 4: RowMappingStrategy + Implementations

**Files:**
- Create: `src/Migration/Strategy/RowMappingStrategy.php`
- Create: `src/Migration/Strategy/RowPerSectionStrategy.php`
- Create: `src/Migration/Strategy/AllRowsInSectionStrategy.php`
- Create: `tests/Unit/Migration/Strategy/RowPerSectionStrategyTest.php`
- Create: `tests/Unit/Migration/Strategy/AllRowsInSectionStrategyTest.php`

**Reference:** Spec sections "RowMappingStrategy", "RowPerSectionStrategy", "AllRowsInSectionStrategy"

- [ ] **Step 1: Create the RowMappingStrategy interface**

```php
// src/Migration/Strategy/RowMappingStrategy.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Strategy;

use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigrationSection;

interface RowMappingStrategy
{
    /**
     * @param list<LegacyElement> $elements Flat sorted element list
     * @param int $pageId Target page ID for parent relationships
     * @param string $zone Target zone
     * @return list<MigrationSection>
     */
    public function buildHierarchy(array $elements, int $pageId, string $zone): array;
}
```

- [ ] **Step 2: Write failing tests for RowPerSectionStrategy**

Test file: `tests/Unit/Migration/Strategy/RowPerSectionStrategyTest.php`

The strategy depends on `ElementGrouper` and `FieldMapper`. Inject real `ElementGrouper` (no side effects) and a `FieldMapper` (also no side effects — pure transformations). Pass a sensible default viewport and viewport key map.

Cover:
1. Each explicit row group → one MigrationSection with one MigrationRow
2. Row field placement: `IsFluid` → Section, `CustomSectionClass` → Section ExtraClass, `Title` → Row, `ExtraClass` → Row
3. Content elements → MigrationColumns with converted GridSettings
4. Implicit boundary group → Section + Row with default/empty values
5. Empty group (adjacent rows) → Section with Row with no columns

- [ ] **Step 3: Implement RowPerSectionStrategy**

Uses `ElementGrouper::group()` to split, then maps each group to `MigrationSection` → `MigrationRow` → `MigrationColumn`s. Calls `FieldMapper::mapGridSettings()` for each content element.

Constructor: `__construct(ElementGrouper $grouper, FieldMapper $mapper, string $defaultViewport, array $viewportKeyMap)`

- [ ] **Step 4: Run tests to verify they pass**

Run: `make test-unit`
Expected: PASS

- [ ] **Step 5: Write failing tests for AllRowsInSectionStrategy**

Test file: `tests/Unit/Migration/Strategy/AllRowsInSectionStrategyTest.php`

Cover:
1. Single Section created, all groups become Rows
2. First row's `IsFluid` and `CustomSectionClass` applied to Section
3. Later row with different `IsFluid` → warning logged, value discarded
4. Later row with different `CustomSectionClass` → warning logged, value discarded
5. Row Title and ExtraClass → Row fields
6. Implicit group → Row with default values

Inject a `LoggerInterface` mock to assert warnings are logged.

- [ ] **Step 6: Implement AllRowsInSectionStrategy**

Same structure as RowPerSection but all groups go under one MigrationSection. Uses `Psr\Log\LoggerInterface` for warnings.

Constructor: `__construct(ElementGrouper $grouper, FieldMapper $mapper, string $defaultViewport, array $viewportKeyMap, LoggerInterface $logger)`

- [ ] **Step 7: Run tests to verify they pass**

Run: `make test-unit`
Expected: PASS

- [ ] **Step 8: Commit**

```
git add src/Migration/Strategy/ tests/Unit/Migration/Strategy/
git commit -m "Add RowMappingStrategy interface and both implementations"
```

---

## Task 5: LegacyDataReader

**Files:**
- Create: `src/Migration/Service/LegacyDataReader.php`
- Create: `tests/Integration/Migration/Service/LegacyDataReaderTest.php`
- Create: `tests/Integration/Migration/Support/LegacyTableSeeder.php`

**Reference:** Spec section "LegacyDataReader"

- [ ] **Step 1: Create LegacyTableSeeder test helper**

`tests/Integration/Migration/Support/LegacyTableSeeder.php`

A helper class that uses raw SQL to INSERT rows into old tables (`BaseElement`, `BaseElement_Live`, `ElementRow`, `ElementRow_Live`, `ElementContent`, `ElementContent_Live`). Also seeds `SiteTree`/`Page` rows with `UseElementalGrid` and `ElementalAreaID` columns, and `ElementalArea` rows.

Important: these old tables don't exist in the new module's schema. The seeder must CREATE them if they don't exist (using `DB::query()` with `CREATE TABLE IF NOT EXISTS`). Define minimal column sets matching what the reader queries.

The seeder should also handle creating `_Live` variants of each table.

- [ ] **Step 2: Write failing integration tests for LegacyDataReader**

Test file: `tests/Integration/Migration/Service/LegacyDataReaderTest.php`

Extends `SilverStripe\Dev\SapphireTest`. Uses `LegacyTableSeeder` in `setUp()`.

Cover:
1. `getEligiblePages()`: returns pages where `UseElementalGrid = 1` and `ElementalAreaID > 0`
2. `getEligiblePages()`: skips pages where `UseElementalGrid = 0`
3. `getEligiblePages()`: returns both pageId and areaId
4. `getEligiblePages()` with `pageIds` filter: only returns matching pages
5. `getElementsForArea()`: returns elements sorted by Sort ASC
6. `getElementsForArea()`: includes grid fields (Size, Offset, Visibility per viewport)
7. `getElementsForArea()`: marks `ElementRow` elements as `isRow: true`
8. `getRowData()`: returns IsFluid and CustomSectionClass for row elements
9. `getContentMediaData()`: returns all media extension fields
10. Stage handling: draft reads from base tables, live reads from `_Live` tables
11. Extension hook: `updateLegacyElements` called and can filter/modify elements

Set `Versioned::set_stage(Versioned::DRAFT)` in `setUp()` per project conventions.

- [ ] **Step 3: Run tests to verify they fail**

Run: `make test-integration` (or filter to `LegacyDataReaderTest`)
Expected: FAIL — class not found

- [ ] **Step 4: Implement LegacyDataReader**

`src/Migration/Service/LegacyDataReader.php`

Uses `SilverStripe\ORM\DB::query()` for raw SQL. Uses `SilverStripe\Core\Extensible` trait for the `updateLegacyElements` hook.

Key implementation:
- `getEligiblePages(string $stage, ?array $pageIds = null)`: JOINs `SiteTree` + `Page` tables (safe for either table having the columns), appends `_Live` to table names for live stage
- `getElementsForArea(int $areaId, string $stage)`: SELECT from `BaseElement` WHERE `ParentID = $areaId` AND `ParentClassName = 'DNADesign\\Elemental\\Models\\ElementalArea'` ORDER BY Sort. Hydrate to `LegacyElement` DTOs.
- `getRowData(int $elementId, string $stage)`: SELECT from `ElementRow` table
- `getContentMediaData(int $elementId, string $stage)`: SELECT from `ElementContent` table
- After hydrating elements, call `getRowData()` and `getContentMediaData()` for each, then invoke `updateLegacyElements` extension hook

Use `DB::prepared_query()` (not `DB::query()`) for parameterized queries on all user-influenced values.

- [ ] **Step 5: Run tests to verify they pass**

Run: `make test-integration`
Expected: PASS

- [ ] **Step 6: Commit**

```
git add src/Migration/Service/LegacyDataReader.php tests/Integration/Migration/
git commit -m "Add LegacyDataReader: raw SQL reads from old elemental tables"
```

---

## Task 6: GridMigrationService

**Files:**
- Create: `src/Migration/Service/GridMigrationService.php`
- Create: `tests/Integration/Migration/Service/GridMigrationServiceTest.php`

**Reference:** Spec section "GridMigrationService" — orchestration flow, staging, transactions, idempotency, dry-run.

This is the largest and most critical component. The integration tests seed old tables, run the migration, and verify the new hierarchy.

- [ ] **Step 1: Write failing integration test — basic end-to-end draft migration**

Test file: `tests/Integration/Migration/Service/GridMigrationServiceTest.php`

Extends `SapphireTest`. Use `LegacyTableSeeder` to create:
- A page with `UseElementalGrid = 1`
- An `ElementalArea`
- An `ElementRow` + two content elements in the area

Run the service with `RowPerSectionStrategy`. Assert:
- One Section created under the page with correct zone
- One Row under the Section
- Two Columns under the Row, each with one content element
- Content elements have correct ParentID → Column, ParentClass → Column::class
- Grid settings converted correctly on Columns
- Content elements exist in `GridElement` table (new IDs, not old BaseElement IDs)

- [ ] **Step 2: Run test to verify it fails**

Run: `make test-integration` (filter to `GridMigrationServiceTest`)
Expected: FAIL — class not found

- [ ] **Step 3: Implement GridMigrationService skeleton**

`src/Migration/Service/GridMigrationService.php`

Constructor: `__construct(LegacyDataReader $reader, FieldMapper $mapper, RowMappingStrategy $strategy, LoggerInterface $logger)`

Method: `run(string $defaultViewport, string $zone, bool $dryRun = false, ?array $pageIds = null): void`

Implement the core flow:
1. Disable auto-scaffolding: `Section::config()->set('auto_scaffold', false)` and same for `Row`
2. Get eligible pages from reader
3. For each page, wrap in `Versioned::withVersionedMode()`:
   a. Set stage to DRAFT
   b. Idempotency check: `Section::get()->filter(['ParentID' => $pageId, 'ParentClass' => SiteTree::class, 'Zone' => $zone])->exists()`
   c. Read draft elements, run through strategy → get MigrationSections
   d. If dry-run: log and skip
   e. Begin transaction via `DB::get_conn()->transactionStart()`
   f. Write hierarchy: Sections → Rows → Columns via ORM
   g. Create content elements via ORM (new GridElement records with mapped fields)
   h. Build old→new ID mappings
   i. Handle live stage: read live elements, match via ID mapping, `writeToStage(Versioned::LIVE)`
   j. Commit transaction
4. Restore auto-scaffolding in finally block
5. Log summary

Key detail: when creating content elements via ORM, use `$newElement = $resolvedClassName::create()` then set all mapped fields, then `$newElement->write()`. The ORM handles writing to the correct subclass tables.

- [ ] **Step 4: Run test to verify it passes**

Run: `make test-integration`
Expected: PASS

- [ ] **Step 5: Commit**

```
git add src/Migration/Service/GridMigrationService.php tests/Integration/Migration/Service/GridMigrationServiceTest.php
git commit -m "Add GridMigrationService: core draft migration orchestration"
```

- [ ] **Step 6: Add integration tests for stage handling and ID mapping**

Add to `GridMigrationServiceTest.php`:
1. Draft+Live: element on both stages → same new ID on both, containers published to live
2. Draft-only: element only on draft → exists in draft only (no live)
3. Live-only: element only on live → new records on both stages (Versioned integrity)
4. Different content on draft vs live → both versions migrated independently with same new ID
5. Draft and live containers (Section/Row/Column) share the same IDs — assert record IDs match across tables
6. `_Versions` records created for both draft and live stages
7. Old element ID → new element ID mapping correctly used for live-stage reconciliation (verify live elements point to same Columns as their draft counterparts)
8. Old element ID → new Column ID mapping assigns correct parents

Use `LegacyTableSeeder` to create draft-only and live-only scenarios by inserting into base tables vs `_Live` tables selectively.

- [ ] **Step 7: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php src/Migration/Service/GridMigrationService.php
git commit -m "Add stage handling: draft+live, draft-only, live-only migrations"
```

- [ ] **Step 8: Add integration tests for table migration and media fields**

Add to `GridMigrationServiceTest.php`:

Table migration:
1. Content element data exists in `GridElement` + subclass tables, NOT in old `BaseElement`
2. Content elements have new IDs (not reusing old `BaseElement` IDs)
3. Old `BaseElement` records left untouched as orphans
4. Subclass data migrated: `ElementContent.HTML` → `ContentElement.HTML`
5. `has_one` relation IDs preserved: MediaImageID passes through, MediaVideoCustomThumbnailID → VideoCustomThumbnailID

Media fields:
6. ContentElement media fields mapped correctly (renames, CSS→enum, gap size scale)
7. ContentColumns Varchar→Int, MediaRatio empty→auto

- [ ] **Step 9: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php src/Migration/Service/GridMigrationService.php
git commit -m "Add media field migration with value transformations"
```

- [ ] **Step 10: Add integration tests for idempotency and dry-run**

Add to `GridMigrationServiceTest.php`:
1. Idempotency: run twice → second run skips, no duplicates
2. Partially migrated state: draft done but live not yet → re-run completes live without touching draft
3. Dry-run: no records created, output describes what would happen

- [ ] **Step 11: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php
git commit -m "Add idempotency and dry-run support"
```

- [ ] **Step 12: Add integration tests for transaction safety**

Add to `GridMigrationServiceTest.php`:
1. Simulate failure mid-page → that page rolled back, other pages unaffected

- [ ] **Step 13: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php src/Migration/Service/GridMigrationService.php
git commit -m "Add transaction-per-page safety with rollback on failure"
```

- [ ] **Step 14: Add integration tests for pseudo rows**

Add to `GridMigrationServiceTest.php`:
1. Elements before first explicit row → implicit Section+Row
2. Elements after last explicit row → implicit Section+Row
3. No explicit rows at all → all elements wrapped in implicit Section+Row

- [ ] **Step 15: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php
git commit -m "Add pseudo row handling: implicit boundary groups"
```

- [ ] **Step 16: Add integration tests for extension hooks**

Add to `GridMigrationServiceTest.php`:
1. `updateLegacyElements`: filter out element → not migrated
2. `updateLegacyElements`: enrich element → extra data arrives in new record
3. `updateElementFieldMapping`: add custom mapping → field written
4. `updateElementFieldMapping`: override default → override takes effect
5. `updateClassNameMapping`: custom FQCN → ClassName updated

Use test-only extensions applied via `$required_extensions` or `Extension::add_extension()` in setUp.

- [ ] **Step 17: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php
git commit -m "Add extension hook integration tests"
```

- [ ] **Step 18: Add integration tests for edge cases and strategy-specific behavior**

Add to `GridMigrationServiceTest.php`:
1. Page with `UseElementalGrid = false` → skipped
2. Page with empty ElementalArea → skipped
3. Adjacent ElementRows with no content → empty Row created
4. RowPerSection: IsFluid→Section, CustomSectionClass→Section, Title→Row, ExtraClass→Row
5. AllRowsInSingleSection: first row's config used, later row warns
6. Multiple element types on one page → each gets correct ClassName
7. Custom element with project-specific extension adding DB fields → extension hook migrates fields correctly (use test-only extension)
8. Sort order preserved through hierarchy
9. Title, ShowTitle, TitleTag, TitleClass, ExtraClass carried over

- [ ] **Step 19: Run tests, fix issues, commit**

Run: `make test-integration`
Expected: PASS

```
git add tests/Integration/Migration/Service/GridMigrationServiceTest.php
git commit -m "Add edge case and strategy-specific integration tests"
```

---

## Task 7: BuildTask Shells

**Files:**
- Create: `src/Migration/Task/MigrateRowsToSectionsTask.php`
- Create: `src/Migration/Task/MigrateRowsToSingleSectionTask.php`

**Reference:** Spec section "BuildTask Shells"

- [ ] **Step 1: Implement MigrateRowsToSectionsTask**

```php
// src/Migration/Task/MigrateRowsToSectionsTask.php
declare(strict_types=1);

namespace WeDevelop\Grid\Migration\Task;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Control\HTTPRequest;
use WeDevelop\Grid\Contract\GridAdapterInterface;
use WeDevelop\Grid\Migration\Service\ElementGrouper;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Service\GridMigrationService;
use WeDevelop\Grid\Migration\Service\LegacyDataReader;
use WeDevelop\Grid\Migration\Strategy\RowPerSectionStrategy;

class MigrateRowsToSectionsTask extends BuildTask
{
    private static string $segment = 'migrate-grid-rows-to-sections';

    protected string $title = 'Migrate grid rows to sections';

    protected string $description = 'Migrates old elemental-grid data: each ElementRow becomes a Section + Row in the new hierarchy.';

    public function run(HTTPRequest $request): void
    {
        // 1. Parse and validate required args
        $defaultViewport = $request->getVar('default_viewport');
        $zone = $request->getVar('zone');
        if (!$defaultViewport || !$zone) {
            echo "Required arguments: default_viewport, zone\n";
            echo "Usage: sake dev/tasks/migrate-grid-rows-to-sections default_viewport=MD zone=main\n";
            return;
        }

        $dryRun = (bool) $request->getVar('dry-run');
        $pageIds = $request->getVar('page-ids')
            ? array_map('intval', explode(',', $request->getVar('page-ids')))
            : null;

        // 2. Derive viewport key map
        $viewportKeyMap = $this->resolveViewportKeyMap(
            $request->getVar('viewport-map'),
            Injector::inst()->get(GridAdapterInterface::class),
        );

        // 3. Wire dependencies and run
        $logger = Injector::inst()->get(LoggerInterface::class);
        $reader = new LegacyDataReader();
        $mapper = new FieldMapper();
        $grouper = new ElementGrouper();
        $strategy = new RowPerSectionStrategy($grouper, $mapper, $defaultViewport, $viewportKeyMap);

        $service = new GridMigrationService($reader, $mapper, $strategy, $logger);
        $service->run($defaultViewport, $zone, $dryRun, $pageIds);
    }

    /**
     * Parse viewport-map arg or derive from adapter.
     * @return array<string, string> old key → new key
     */
    private function resolveViewportKeyMap(?string $viewportMapArg, GridAdapterInterface $adapter): array
    {
        if ($viewportMapArg) {
            $map = [];
            foreach (explode(',', $viewportMapArg) as $pair) {
                [$old, $new] = explode('=', $pair, 2);
                $map[trim($old)] = trim($new);
            }
            return $map;
        }

        // Auto-derive: case-insensitive match of old keys against adapter viewports
        $adapterViewports = $adapter->getViewportDefinitions();
        $oldKeys = ['XS', 'SM', 'MD', 'LG', 'XL'];
        $map = [];
        foreach ($oldKeys as $oldKey) {
            foreach ($adapterViewports as $newKey => $label) {
                if (strtolower($oldKey) === strtolower($newKey)) {
                    $map[$oldKey] = $newKey;
                    break;
                }
            }
        }
        return $map;
    }
}
```

- [ ] **Step 2: Implement MigrateRowsToSingleSectionTask**

Same structure but wires `AllRowsInSectionStrategy` instead. Copy the task, change the segment, title, description, and strategy class.

- [ ] **Step 3: Commit**

```
git add src/Migration/Task/
git commit -m "Add BuildTask shells: MigrateRowsToSections and MigrateRowsToSingleSection"
```

---

## Task 8: QA and Final Verification

- [ ] **Step 1: Run full unit test suite**

Run: `make test-unit`
Expected: All pass, no regressions

- [ ] **Step 2: Run full integration test suite**

Run: `make test-integration`
Expected: All pass, no regressions

- [ ] **Step 3: Run PHPStan**

Run: `make analyse`
Expected: No errors at level max

- [ ] **Step 4: Fix any PHPStan issues**

Address type errors, missing PHPDoc annotations per project conventions (positive-int, non-empty-string, class-string, etc.). See CLAUDE.md "Type Precision" section.

- [ ] **Step 5: Commit any fixes**

```
git add -A
git commit -m "Fix PHPStan type errors in migration code"
```

- [ ] **Step 6: Run full QA suite**

Run: `make qa`
Expected: All pass

- [ ] **Step 7: Commit if needed, then mark complete**
