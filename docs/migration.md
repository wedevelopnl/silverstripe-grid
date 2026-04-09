# Migrating from Elemental / ElementalGrid

This module ships with a migration tool that converts legacy content from the old flat elemental model into the new Section → Row → Column → Content hierarchy. Two sources are supported:

- **WeDevelop ElementalGrid** (SilverStripe 5): pages with `UseElementalGrid` flag and an `ElementalArea`
- **Plain `dnadesign/silverstripe-elemental`**: pages with an `ElementalArea` but no row elements

The migration runs as a `BuildTask`, is idempotent, supports dry-runs, and handles draft/live stages automatically.

## Requirements

- This module is installed, configured and `dev/build` has been run successfully.
- The legacy database tables (`BaseElement`, `ElementalArea`, `ElementContent`, optionally `ElementRow`) are still present in the database. The old PHP code does **not** need to be installed — the migration reads the old tables via raw SQL.
- A `GridAdapterInterface` implementation is bound via DI (the default is `BootstrapAdapter`).

## Before You Begin

Read this section before running the task on a production database.

- **Back up your database.** The migration writes into `GridElement`, `GridElement_Live`, `Section`, `Row`, `Column` and `ContentElement` tables and performs Versioned draft/live reconciliation. A bad run is difficult to unwind without a backup.
- **Element IDs change.** Migrated content elements receive new primary keys when inserted into `GridElement`. External references to the old `BaseElement` IDs (shortcodes in other content, custom reports, outbound links) will not follow automatically.
- **Legacy tables are left intact.** The tool does not drop `BaseElement`, `ElementRow`, `ElementContent`, or `ElementalArea`. Orphaned rows in those tables let you re-run the migration if something looks wrong. Drop them manually once you are satisfied with the result.
- **Only `ElementContent` is mapped by default.** The built-in field mapper converts `DNADesign\Elemental\Models\ElementContent` to `WeDevelop\Grid\Model\ContentElement`. Any custom element subclass must either already extend `GridElement`, or be registered via the `updateClassNameMapping` extension hook described in [Customising the Migration](#customising-the-migration).
- **Media-field CSS values are Bootstrap-specific.** Field values like `ContentVerticalAlign` (`align-items-center` → `center`) and `MediaPosition` (`order-1 order-md-2` → `last-on-desktop`) are translated using hardcoded Bootstrap class names. Sites that used the old module with a different CSS framework need a custom `FieldMapper` — see [Customising the Migration](#customising-the-migration).
- **Draft-deleted content will reappear on draft.** To keep the Versioned contract intact, elements that only exist on live are inserted on **both** draft and live. If your editors had deleted content from draft without publishing, those elements will become visible on draft again after migration.

## Choosing a Strategy

The tool provides two tasks. They differ only in how they map legacy `ElementRow` records into the new hierarchy.

| Task | Segment | When to use |
|------|---------|-------------|
| `MigrateRowsToSectionsTask` | `migrate-grid-rows-to-sections` | Each legacy `ElementRow` becomes its own `Section` (with one `Row` inside). Use when rows were used as semantic section boundaries. |
| `MigrateRowsToSingleSectionTask` | `migrate-grid-rows-to-single-section` | All legacy rows become `Row`s under a single `Section` per page. Use when rows were literal grid rows inside one visual section. |

For sites migrating from **plain `dnadesign/silverstripe-elemental`** (no rows at all) both strategies behave identically: each page becomes one `Section` with one `Row` containing all content.

### Shared starting point

Both strategies start from the same flat legacy `ElementalArea`:

```
BEFORE — legacy flat ElementalArea on page "About Us"

Page "About Us"
└── ElementalArea
    ├── [1] ElementRow     "Hero row"       (CustomSectionClass=hero)
    ├── [2] ElementContent "Headline"       (md=12)
    ├── [3] ElementContent "Subheadline"    (md=8, offset=2)
    ├── [4] ElementRow     "Team row"
    ├── [5] ElementContent "Team member A"  (md=4)
    ├── [6] ElementContent "Team member B"  (md=4)
    └── [7] ElementContent "Team member C"  (md=4)
```

### Strategy A — `MigrateRowsToSectionsTask`

One Section per legacy `ElementRow`. Each Section contains exactly one Row.

```
AFTER — rows-to-sections

Page "About Us"
├── Section  (zone=main, sort=1, ExtraClass=hero)
│   └── Row  "Hero row"                     (sort=1)
│       ├── Column  md=12
│       │   └── Content "Headline"
│       └── Column  md=8, offset=2
│           └── Content "Subheadline"
│
└── Section  (zone=main, sort=2)
    └── Row  "Team row"                     (sort=1)
        └── Column  md=4    ◄── consolidated: three siblings share one Column
            ├── Content "Team member A"
            ├── Content "Team member B"
            └── Content "Team member C"
```

### Strategy B — `MigrateRowsToSingleSectionTask`

A single Section per page, with legacy rows preserved as Rows inside it.

```
AFTER — rows-to-single-section

Page "About Us"
└── Section  (zone=main, sort=1, ExtraClass=hero)
    ├── Row  "Hero row"                     (sort=1)
    │   ├── Column  md=12
    │   │   └── Content "Headline"
    │   └── Column  md=8, offset=2
    │       └── Content "Subheadline"
    │
    └── Row  "Team row"                     (sort=2)
        └── Column  md=4    ◄── consolidated: three siblings share one Column
            ├── Content "Team member A"
            ├── Content "Team member B"
            └── Content "Team member C"
```

**Note on Section titles.** Migrated Sections are created with an empty `Title` field. The legacy `ElementRow.Title` is carried over to the new `Row.Title`, not the Section. Adjust titles in the CMS afterwards if needed.

**Note on `CustomSectionClass`.** In Strategy A the `CustomSectionClass` from each `ElementRow` becomes the `ExtraClass` of its matching Section. In Strategy B the `CustomSectionClass` of the **first** row is used for the single Section; conflicting values from later rows are discarded and a warning is logged.

## Running the Migration

Both tasks are standard SilverStripe `BuildTask`s and accept the same options. Always start with a dry run.

```bash
# Dry run — writes nothing, logs what would be created
vendor/bin/sake dev/tasks/migrate-grid-rows-to-sections \
    --default-viewport=MD --zone=main --dry-run

# Full migration
vendor/bin/sake dev/tasks/migrate-grid-rows-to-sections \
    --default-viewport=MD --zone=main

# Migrate specific pages only (useful for staged rollouts)
vendor/bin/sake dev/tasks/migrate-grid-rows-to-sections \
    --default-viewport=MD --zone=main --page-ids=1,5,12
```

Replace `migrate-grid-rows-to-sections` with `migrate-grid-rows-to-single-section` to use Strategy B.

### Options Reference

| Option | Required | Example | Description |
|--------|----------|---------|-------------|
| `--default-viewport` | yes | `MD` | Legacy viewport key used as the default for the new `GridSettings`. The element's value in this viewport becomes `default`; other viewports are written as overrides only when they differ. |
| `--zone` | yes | `main` | Zone name for the created Sections. Sort order is scoped per zone. |
| `--dry-run` | no | (flag) | Log planned writes and skip all database changes. Exit code is 0 on success even when nothing was written. |
| `--viewport-map` | no | `XS=xs,SM=sm,MD=md,LG=lg,XL=xl` | Map legacy viewport keys to the active adapter's viewport keys. Derived automatically via case-insensitive matching when omitted — provide this explicitly when migrating across CSS frameworks with different viewport names. |
| `--page-ids` | no | `1,5,12` | Comma-separated page IDs to migrate. If omitted, all eligible pages are migrated. |

If a run finishes with failures, the task exits with a non-zero status and the failing page IDs are logged as errors. Pages that failed remain un-migrated and can be re-run after the cause is fixed.

## What Gets Migrated

- **Hierarchy.** The flat legacy list becomes Section → Row → Column → Content.
- **Grid settings.** The 15 legacy per-viewport fields (`SizeXS`…`SizeXL`, `OffsetXS`…`OffsetXL`, `VisibilityXS`…`VisibilityXL`) are converted into the new `GridSettings` value object (`default` + per-viewport `overrides`). An override is only emitted when it differs from the default.
- **Grid settings clamping.** Invalid legacy values are clamped to keep the write transaction alive:
  - `width` is clamped to `[1, columnCount]`.
  - `offset` is clamped to `[0, columnCount - 1]`, then reduced further if `width + offset > columnCount`.
  - `Size = 0` is treated as "not set" and promoted to the full column width (required for plain `dnadesign/silverstripe-elemental` sites where Size columns do not exist).
  - Every clamp is logged as a warning so you can review the migration log and decide whether to correct data in the CMS.
- **Element metadata.** `Title`, `ShowTitle`, `TitleClass`, `ExtraClass`, `Sort`. `TitleTag` defaults to `h2` when the legacy value is empty.
- **Row metadata.** `ElementRow.Title` and `ElementRow.ExtraClass` map to the new `Row`. `ElementRow.CustomSectionClass` maps to the parent `Section.ExtraClass`.
- **Media fields.** Fields added by `ElementContentExtension` (`ContentColumns`, `ContentVerticalAlign`, `ExtraColumnGap`, `MediaType`, `MediaCaption`, `MediaRatio`, `MediaPosition`, `MediaImage`, video fields) are translated into the equivalent fields on `BlockMediaExtension` — including CSS-class → enum conversions and a discrete scale mapping for `ExtraColumnGap`.
- **Draft and live stages.** The migration writes the draft hierarchy first, then reconciles the live stage. Elements that exist on both stages reuse the draft IDs; elements that only exist on live get new records on both stages to preserve Versioned integrity.

## What Is NOT Migrated

- `ElementRow.IsFluid`. This was a per-row flag in the old module and has no per-instance equivalent in the new Section model.
- `ElementRow.TitleTag` and `ElementRow.TitleClass`.
- The legacy `BaseElement` / `ElementRow` / `ElementContent` / `ElementalArea` rows themselves. They are left in the database for manual cleanup.
- Custom element subclasses that are not configured via an extension hook.

## Grid Settings Consolidation

When a run of consecutive elements share **identical** `GridSettings` (same default and overrides), the migration groups them into a single `Column`. This keeps migrated hierarchies close to what a human would build.

```
BEFORE — three legacy elements with identical grid settings

ElementRow "Team row"
├── ElementContent "Team member A"  (md=4)
├── ElementContent "Team member B"  (md=4)
└── ElementContent "Team member C"  (md=4)

AFTER — grouped into a single Column

Row
└── Column  md=4
    ├── Content "Team member A"
    ├── Content "Team member B"
    └── Content "Team member C"
```

Grouping only applies to **consecutive runs**. A single element with different settings breaks the run:

```
BEFORE — middle element breaks the run

ElementContent "A"  (md=4)
ElementContent "B"  (md=6)   ◄── differs
ElementContent "C"  (md=4)

AFTER — three separate Columns

Row
├── Column md=4  →  Content "A"
├── Column md=6  →  Content "B"
└── Column md=4  →  Content "C"
```

The original sort order is always preserved. The side effect is that sibling elements sharing settings will share a parent `Column` after migration; any code or shortcode that assumes one element per column will need to be adapted.

## Idempotency and Re-running

The migration skips any page that already has at least one `Section` on draft for the target `--zone`. This makes it safe to re-run after fixing an extension or adding `--page-ids` to retry a failed subset.

A partial migration (draft written, live reconciliation failed) will not be retried automatically — the idempotency check only considers draft. If you need to redo a page, delete the new Section tree for that page first.

## Customising the Migration

Three extension points are available via `SilverStripe\Core\Extensible`. Register a `DataExtension` on the service classes below.

### 1. Custom element class mapping

`GridMigrationService` calls `updateClassNameMapping($newClassName, $oldClassName)` before instantiating each new element. Use this to redirect legacy subclasses to their new-world equivalents.

```php
use SilverStripe\Core\Extension;

class MyMigrationExtension extends Extension
{
    public function updateClassNameMapping(string &$newClassName, string $oldClassName): void
    {
        if ($oldClassName === 'App\\Elements\\TeaserElement') {
            $newClassName = \App\Grid\TeaserElement::class;
        }
    }
}
```

Register it in YAML:

```yaml
WeDevelop\Grid\Migration\Service\GridMigrationService:
  extensions:
    - App\Migration\MyMigrationExtension
```

The resolved class **must** extend `WeDevelop\Grid\Model\GridElement`, otherwise the migration aborts the page with a `RuntimeException`.

### 2. Post-processing individual elements

`GridMigrationService` also calls `updateElementFieldMapping($newElement, $legacyElement)` after the default field mapping has been applied. Use this to copy subclass-specific fields that the default mapper does not know about.

```php
public function updateElementFieldMapping(
    \WeDevelop\Grid\Model\GridElement $newElement,
    \WeDevelop\Grid\Migration\DTO\LegacyElement $legacyElement,
): void {
    if ($newElement instanceof \App\Grid\TeaserElement && $legacyElement->mediaData !== null) {
        $newElement->TeaserLinkURL = (string) ($legacyElement->mediaData->fields['TeaserLinkURL'] ?? '');
    }
}
```

### 3. Filtering or augmenting the legacy element list

`LegacyDataReader` calls `updateLegacyElements($elements, $areaId, $stage)` after reading a page's elements. Use this to drop unsupported elements or inject additional data.

```php
/**
 * @param list<\WeDevelop\Grid\Migration\DTO\LegacyElement> $elements
 */
public function updateLegacyElements(array &$elements, int $areaId, string $stage): void
{
    $elements = array_values(array_filter(
        $elements,
        static fn ($element) => $element->className !== 'App\\Elements\\DeprecatedElement',
    ));
}
```

Register the extension on `WeDevelop\Grid\Migration\Service\LegacyDataReader` using the same YAML pattern as above.

### Replacing the built-in CSS-class lookups

The Bootstrap-specific vertical alignment, media position, and gap-size lookup tables in `FieldMapper` are constructor arguments (with hardcoded Bootstrap defaults). The bundled tasks do not expose a way to swap them from YAML or CLI — replacing them requires a small subclass of the migration task that builds its own `FieldMapper`:

```php
use Psr\Log\LoggerInterface;
use WeDevelop\Grid\Migration\Service\FieldMapper;
use WeDevelop\Grid\Migration\Task\MigrateRowsToSectionsTask;

class MigrateRowsToSectionsTailwindTask extends MigrateRowsToSectionsTask
{
    private static string $segment = 'migrate-grid-rows-to-sections-tailwind';

    // Override createStrategy() to pass a FieldMapper built with your maps.
}
```

In practice, the simpler path is an `updateElementFieldMapping` extension (option 2 above) that overwrites the affected fields after the default mapping has run.

## After Migration

1. Run `dev/build flush=1` to make sure the schema is consistent.
2. Spot-check a handful of migrated pages in the CMS and on the frontend. Pay particular attention to:
   - Section and Row titles (Sections are always created untitled).
   - Elements that shared grid settings and now share a parent Column.
   - Media blocks that relied on Bootstrap CSS classes for alignment or ordering.
3. Review the log output for `warning` entries — each one corresponds to a clamp, an unresolved mapping, or a `customSectionClass` conflict resolved by Strategy B.
4. Once you are satisfied, drop the legacy tables manually (`BaseElement`, `BaseElement_Live`, `ElementRow`, `ElementRow_Live`, `ElementContent`, `ElementContent_Live`, `ElementalArea`, `ElementalArea_Live`, and any legacy subclass tables) in a separate migration step.

## Troubleshooting

**"Page X already migrated for zone Y, skipping."** — The idempotency check found at least one `Section` for that page and zone on draft. If you need to redo the page, delete its new Section tree first.

**"Resolved class … does not extend GridElement."** — A legacy element class has no mapping to a new class that extends `WeDevelop\Grid\Model\ContentElement` / `GridElement`. Register an `updateClassNameMapping` extension (option 1) or update the PHP class hierarchy.

**Media blocks show the wrong alignment or order.** — The default CSS-class lookups expect Bootstrap values (`align-items-center`, `order-1 order-md-2`, …). Fix the affected fields from an `updateElementFieldMapping` extension, or subclass the task and provide custom lookup tables to `FieldMapper`.

**Viewport overrides are missing after migration.** — The automatic viewport-key mapping is case-insensitive but requires at least a case-insensitive match between legacy keys (`XS`, `SM`, `MD`, `LG`, `XL`) and the active adapter's viewport keys. Migrating to an adapter with different names (for example Bulma's `mobile`, `tablet`, `desktop`) requires an explicit `--viewport-map` argument.

**Draft-deleted content reappeared on draft.** — This is expected. Live-only elements are recreated on both stages to preserve Versioned integrity.
