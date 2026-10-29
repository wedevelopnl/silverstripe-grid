# Migrating from Elemental / ElementalGrid

This module ships with a migration tool that converts legacy content from the old flat elemental model into the new Section → Row → Column → Content hierarchy. Two sources are supported:

- **WeDevelop ElementalGrid** (SilverStripe 5): pages with `UseElementalGrid` flag and an `ElementalArea`
- **Plain `dnadesign/silverstripe-elemental`**: pages with an `ElementalArea` but no row elements

The migration runs as a `BuildTask`, is idempotent, supports dry-runs, and handles draft/live stages automatically.

## Requirements

- **The source site must run SilverStripe 5.4.x with `dnadesign/silverstripe-elemental` 5.4.x, and — on an ElementalGrid site — `wedevelopnl/silverstripe-elemental-grid` 5.1.5.** The migration is built and tested against the legacy schema of exactly those versions, for both supported sources; older releases store their content differently and are not supported. Upgrade the source site to these versions (and run `dev/build` there) before you upgrade it to SS6. A SilverStripe 4 site performs SilverStripe's standard SS4→SS5 upgrade first. The task cannot read these versions from the database, so it asks you to confirm them — see [Confirmation prompts](#confirmation-prompts).
- This module is installed, configured and `dev/build` has been run successfully.
- The legacy database tables are still present in the database: `Element` and `Element_Live` (where `dnadesign/silverstripe-elemental` 5.x stores every element's base record), `Element_Versions`, `ElementalArea`, `ElementContent`, and optionally `ElementRow`, each with its `_Live` table. The old PHP code does **not** need to be installed — the migration reads the old tables via raw SQL. When `Element` or `Element_Live` is missing, the task refuses to start and changes nothing; when `Element_Versions` is missing, a run that writes stops before the first page (see [Before You Begin](#before-you-begin)).
- **A hand-added `BaseElement` table or view is not read.** Some sites worked around an earlier release of this tool, which looked for the legacy elements under `BaseElement`, by adding a `BaseElement` (or `BaseElement_Localised`) copy or view of `Element`. The migration reads `Element` only, so such a copy is ignored and can be dropped. On a Fluent site the workaround could also hide the translations: with a `BaseElement` view and no `BaseElement_Localised` table, the earlier release detected no localised content and migrated the default locale only. If you migrated a Fluent site that way, check its translations.
- The `SS_GRID_ADAPTER` environment variable **must** be set to a bundled preset (`bootstrap` or `tailwind`, case-insensitive) or to the fully-qualified class name of a custom adapter implementing both `GridAdapterInterface` and `ContentLayoutAdapterInterface` (subclassing `GridAdapter` satisfies both). There is no default: when the variable is unset, empty, or invalid the container throws a `RuntimeException` at boot, which aborts `dev/build` **and** every migration task before any work is done. Set it in your environment (e.g. `.env`) before migrating.

## Before You Begin

Read this section before running the task on a production database.

- **Back up your database.** The migration writes into `GridElement`, `GridElement_Live`, `Section`, `Row`, `Column` and `ContentElement` tables and performs Versioned draft/live reconciliation. A bad run is difficult to unwind without a backup. The task asks you to confirm the backup exists before it writes anything.
- **Element IDs change.** Migrated content elements receive new primary keys when inserted into `GridElement`. External references to the old `Element` IDs (shortcodes in other content, custom reports, outbound links) will not follow automatically. Items a block owns (slides, gallery images) can follow it during the run — see [hook 10](#10-re-attaching-items-a-block-owns).
- **New element IDs start above the legacy ones.** Before the first page, a run that writes raises the numbering of `GridElement` above the highest legacy element ID found in `Element`, `Element_Live` and `Element_Versions`. A new ID can then never equal a legacy one, so an item that still points at a legacy number has not been moved yet. Only the counter moves, and only upward; a dry run leaves it alone. MySQL only.
- **Legacy tables are left intact.** The tool does not drop `Element`, `ElementRow`, `ElementContent`, or `ElementalArea`. Orphaned rows in those tables let you re-run the migration if something looks wrong. Drop them manually once you are satisfied with the result.
- **Only `ElementContent` is mapped by default.** The built-in field mapper converts `DNADesign\Elemental\Models\ElementContent` to `WeDevelop\Grid\Model\ContentElement`. Any custom element subclass must either already extend `GridElement`, or be registered via the `updateClassNameMapping` extension hook described in [Customising the Migration](#customising-the-migration).
- **Media without a column to land in stays behind.** Media capability is opt-in: `ContentElement` ships lean (HTML only), so the media columns (`MediaType`, `MediaImage`, video fields, …) only exist once you apply `BlockMediaExtension` to it (or to your own content subclass). The migration runs either way: without the extension every text block keeps its HTML on draft and live, and the media settings, which have no column to go to, stay in the legacy `ElementContent` tables. The run then ends with one warning that counts the text blocks affected and names the legacy columns that held a value, for example:

  ```
  3 text block(s) had media settings that were not carried over: MediaType, MediaImageID, MediaVideoFullURL. No column holds them; the values remain in the legacy ElementContent tables.
  ```

  The same line goes to the log at warning level. A block present on both draft and live, or in several locales, counts once; plain-text blocks never trigger it. To keep the media, apply the extension in your app config **before** running the task:

  ```yaml
  WeDevelop\Grid\Model\ContentElement:
    extensions:
      - WeDevelop\Grid\Extensions\BlockMediaExtension
  ```

  Switching it on afterwards does **not** backfill the media: a re-run skips every page that already has Sections. When you remap legacy text blocks to your own `ContentElement` subclass via `updateClassNameMapping` and apply the extension to that subclass only, the media lands in that subclass's table.
- **Media-field CSS values are Bootstrap-specific.** Field values like `ContentVerticalAlign` (`align-items-center` → `center`) and `MediaPosition` (`order-1 order-md-2` → `last-on-desktop`) are translated using hardcoded Bootstrap class names. Sites that used the old module with a different CSS framework need a custom `FieldMapper` — see [Customising the Migration](#customising-the-migration).
- **Draft-deleted content will reappear on draft.** To keep the Versioned contract intact, elements that only exist on live are inserted on **both** draft and live. If your editors had deleted content from draft without publishing, those elements will become visible on draft again after migration.

## Choosing a Strategy

The `migrate-grid` task accepts a `--strategy` option that controls how legacy `ElementRow` records are mapped into the new hierarchy.

| `--strategy` value | When to use |
|--------------------|-------------|
| `sections` (default) | Each legacy `ElementRow` becomes its own `Section` (with one `Row` inside). Use when rows were used as semantic section boundaries. |
| `single-section` | All legacy rows become `Row`s under a single `Section` per page. Use when rows were literal grid rows inside one visual section. |

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

### Strategy A — `--strategy=sections`

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
        ├── Column  md=4    ◄── one Column per block: the three sit side by side
        │   └── Content "Team member A"
        ├── Column  md=4
        │   └── Content "Team member B"
        └── Column  md=4
            └── Content "Team member C"
```

### Strategy B — `--strategy=single-section`

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
        ├── Column  md=4    ◄── one Column per block: the three sit side by side
        │   └── Content "Team member A"
        ├── Column  md=4
        │   └── Content "Team member B"
        └── Column  md=4
            └── Content "Team member C"
```

**Note on Section titles.** Migrated Sections are created with an empty `Title` field. The legacy `ElementRow.Title` is carried over to the new `Row.Title`, not the Section. Adjust titles in the CMS afterwards if needed.

**Note on `CustomSectionClass`.** With `--strategy=sections` the `CustomSectionClass` from each `ElementRow` becomes the `ExtraClass` of its matching Section. With `--strategy=single-section` the `CustomSectionClass` of the **first** row is used for the single Section; conflicting values from later rows are discarded and a warning is logged.

## Running the Migration

`migrate-grid` is a standard SilverStripe `BuildTask`. Always start with a dry run.

```bash
# Dry run — writes nothing, logs what would be created (no confirmation prompt)
vendor/bin/sake tasks:migrate-grid \
    --default-viewport=MD --zone=main --strategy=sections --dry-run

# Full migration — asks you to confirm the source versions and your backup before writing anything
vendor/bin/sake tasks:migrate-grid \
    --default-viewport=MD --zone=main --strategy=sections

# Full migration, non-interactive (CI, scripts): both acknowledgements are required
vendor/bin/sake tasks:migrate-grid \
    --default-viewport=MD --zone=main --strategy=sections \
    --confirm-source-up-to-date --confirm-backup

# Migrate specific pages only (useful for staged rollouts)
vendor/bin/sake tasks:migrate-grid \
    --default-viewport=MD --zone=main --strategy=sections --page-ids=1,5,12 \
    --confirm-source-up-to-date --confirm-backup
```

Replace `--strategy=sections` with `--strategy=single-section` to use Strategy B. The `--strategy` option defaults to `sections` when omitted.

### Confirmation prompts

Every run, dry runs included, opens with a banner restating the two preconditions: the supported source versions, and that the migration cannot be undone without a backup.

A run without `--dry-run` then asks two `[y/N]` questions before any data is written:

1. Is the source site on SilverStripe 5.4.x with `dnadesign/silverstripe-elemental` 5.4.x (and `wedevelopnl/silverstripe-elemental-grid` 5.1.5, if the site uses it)?
2. Do you have a restorable backup of this database?

Answering anything other than `y`/`yes` to either aborts the run with a successful exit code and no database changes. Passing `--confirm-source-up-to-date` or `--confirm-backup` answers its question in advance, so only the remaining one is asked.

When stdin is not a TTY (CI pipelines, `sake` called from a script), there is nothing to prompt: the task refuses to run, names the missing flags and exits with a failure code unless **both** flags are passed. There is deliberately no single flag that skips both questions. `--dry-run` never asks.

### Options Reference

| Option | Required | Default | Description |
|--------|----------|---------|-------------|
| `--default-viewport` | yes | — | Legacy viewport key used as the default for the new `GridSettings`. The element's value in this viewport becomes `default`; other viewports are written as overrides only when they differ. |
| `--zone` | yes | — | Zone name for the created Sections. Sort order is scoped per zone. |
| `--strategy` | no | `sections` | Row mapping strategy: `sections` (one Section per legacy ElementRow) or `single-section` (all rows under one Section per page). Default `sections`. |
| `--dry-run` | no | (flag) | Log planned writes and skip all database changes. Asks no confirmation questions. Exit code is 0 on success even when nothing was written. |
| `--confirm-source-up-to-date` | no | (flag) | Acknowledge the source site is on the supported versions (see [Requirements](#requirements)), answering that question in advance. Required, together with `--confirm-backup`, for non-interactive runs when not using `--dry-run`. |
| `--confirm-backup` | no | (flag) | Acknowledge a restorable database backup exists, answering that question in advance. Required, together with `--confirm-source-up-to-date`, for non-interactive runs when not using `--dry-run`. |
| `--viewport-map` | no | — | Map legacy viewport keys to the active adapter's viewport keys, as comma-separated `OLD=new` pairs (e.g. `MD=desktop,LG=widescreen`). Derived automatically via case-insensitive matching when omitted — provide this explicitly when migrating across CSS frameworks with different viewport names. A pair that is malformed, names an unknown legacy key, or names a viewport the active adapter does not expose aborts the run. |
| `--page-ids` | no | — | Comma-separated page IDs to migrate. If omitted, all eligible pages are migrated. |
| `--stop-on-first-failure` | no | (flag) | Halt the batch on the first page that fails to migrate. By default the task continues past failures and logs the failing page IDs at the end. |

### Summary line

Every run that gets past the up-front checks ends with one summary line:

```
Migrated 3 page(s) and 14 content block(s); 0 page(s) skipped as already migrated, 0 failed.
```

A content block is a legacy element that is not an `ElementRow` (rows become the Section/Row/Column structure). An element present on both draft and live counts once. Pages skipped by the idempotency check and pages that failed contribute no blocks. A dry run prints the same counts as `Would migrate …`.

`migrate-grid-with-fluent` prints one line per locale pass, prefixed with the locale code (`[nl_NL] Migrated …`). The lines are not added up: a page converted in two locales is one page in each line.

When legacy content areas are shared by several pages, the run then lists them, each with all of its pages, and logs one warning per area. Every page gets its own copy of a shared area's blocks:

```
Shared content areas:
  Area 100: pages 4, 9
  Area 230: pages 12, 13
```

A shared area's pages may be migrated over several runs (`--page-ids`, or a re-run after a failure): a later run's copy is still handed the first copy an earlier run wrote (see [hook 10](#10-re-attaching-items-a-block-owns)).

When the run left pages without a grid because their current type no longer carries their content blocks (see [What Gets Migrated](#what-gets-migrated)), it lists every one of them, with its page ID, title, current type and reason, followed by a count. A dry run closes with `Dry run: …` instead of `Done: …`. Skipping a page does not fail the run, and nothing is listed when no page was skipped:

```
Skipped pages:
  - Page #42 "Summer offers" (RedirectorPage): this page type is never upgraded
  - Page #57 "Our team" (TeamPage): its current type no longer has the old content blocks (they belong to HolidayPage)
Done: 2 page(s) skipped.
```

`migrate-grid-with-fluent` lists a skipped page once per run, not once per locale.

If a run finishes with failures, the summary line is followed by `Check logs for details.`, the task exits with a non-zero status and the failing page IDs are logged as errors. Pages that failed remain un-migrated and can be re-run after the cause is fixed.

## Multi-locale (Fluent) sites

On a Fluent site, run `migrate-grid-with-fluent` instead of `migrate-grid`. The plain task **refuses to run** when it detects either a locale-isolated target grid (`GridElement` carrying Fluent's isolated extension) or localised legacy tables, and points you here. The isolation check catches even single-locale legacy content, which table-shape detection alone cannot see.

```bash
vendor/bin/sake tasks:migrate-grid-with-fluent \
    --default-viewport=MD --zone=main --strategy=sections --dry-run
```

The task accepts the same options as `migrate-grid` (see the [Options Reference](#options-reference)).

### Additional prerequisites

- **The legacy `Element_Localised` / `*_Localised` (and `_Live`) tables must still be present.** If `dev/build` moved them to `_obsolete_*` after the old element relation was dropped, rename them back first — the migration reads legacy tables by name.
- **A page table's per-locale `ElementalAreaID` must be back under its own name.** When a page type stored its elemental area per locale, its `<Table>_Localised` table holds the `ElementalAreaID` column. If `dev/build` renamed that table to `_obsolete_<Table>_Localised` or its live companion to `_obsolete_<Table>_Localised_Live`, the task refuses to start — also under `--dry-run` — names the page table and each table still to rename, and changes nothing. Rename both back to `<Table>_Localised` and `<Table>_Localised_Live`, then run the task again.
- **Every locale to migrate must exist in the SS6 `Locale` table.** The task migrates the locales Fluent knows now; legacy rows stored under a locale code that is no longer configured are ignored.
- Fluent must be installed and wired into the grid as described in [Fluent (multi-locale) support](fluent.md).

### How the task works

The task migrates per locale: the default locale first, then each additional locale in turn, each inside a `FluentState` context, writing isolated per-locale `GridElement` trees.

It inspects the database table shape to determine which Fluent strategy the SilverStripe 5 site used:

| Legacy table shape | Detected model | Behaviour |
|--------------------|----------------|-----------|
| `Element_Localised` present | Field-localised | Shared element structure; per-locale content read from `*_Localised` tables and overlaid onto the base row. |
| `Element.LocaleID` column present | Isolated | Separate element rows exist per locale; each locale's rows are filtered by `LocaleID`. |
| Neither | Single-locale | No locale-specific data; elements are migrated once into the default locale only. |
| Both | Unsupported | Ambiguous mixed configuration — the task aborts with an error. |

It also checks every page table that carries `ElementalAreaID`, independently of the element model above:

| Page table shape | Area per page | Locales migrated |
|------------------|---------------|------------------|
| `<Table>_Localised` has an `ElementalAreaID` column | One area per locale, read from `<Table>_Localised` (draft) and `<Table>_Localised_Live` (live) | Every locale |
| No such column | One area shared by every locale (the page table's own column) | The default locale only, unless the element model above is field-localised or isolated |

A site can mix both: a page type whose area is per locale next to page types that share one area. Each page follows the row of its own table. When any page table is per locale, the task migrates every locale; a page whose table shares one area across locales is still migrated in the default locale only, unless the elements themselves are localised.

> **Isolated model prerequisite — Locale IDs must be stable.** The migration filters legacy element rows by `LocaleID`, treating those integers as equal to the current Fluent `Locale` record IDs. That holds for a standard in-place SilverStripe 5 → 6 upgrade, where the same database is carried forward and the `Locale` rows keep their original IDs. **If the `Locale` records were deleted and recreated between the time the legacy content was authored and when you run the migration, the IDs will differ, and the Isolated per-locale filter may select the wrong content or return nothing at all.** Verify your `Locale` table IDs are unchanged before running the task on an Isolated site.

### What differs per locale

**Widths and offsets are locale-invariant; visibility is not.** The Columns (one per block) take their `Size`/`Offset` grid settings from the base element rows and apply them equally across all locales. Per-locale width and offset differences present in the legacy data are not honoured — an accepted limitation of a one-shot migration. `Visibility` follows the locale: Fluent localises the legacy `VisibilityXS`…`VisibilityXL` columns into `Element_Localised`, so a block hidden in one locale only is hidden in that locale's Column only. Content fields (`Title`, `HTML`, media text) and row fields (a Row's `Title` and `ExtraClass`, a Section's `ExtraClass` from `CustomSectionClass`, and your own `ElementRow` columns) differ per locale too, on draft and live alike. In the field-localised model an element's `Element_Localised` values, and a row's `ElementRow_Localised` values, are overlaid onto its base record.

**Untranslated elements fall back to base content.** In the field-localised model, an element with no `*_Localised` row for a given locale is migrated using its base content. This matches Fluent's render-time fallback and ensures nothing is silently omitted.

**Each locale's blocks come from its own area.** On a page table whose area is per locale, every locale is converted from the area its `<Table>_Localised` row points at — the draft area for the draft grid, the live area (`<Table>_Localised_Live`) for the live one. The page table's own `ElementalAreaID` is not used for that page, even though it points at whichever locale's area was saved last; only the default locale falls back to it — the draft column for draft, the `_Live` column for live — and only when the page has no draft `_Localised` row in the default locale.

**A locale without its own area gets a copy of its fallback's blocks.** When a page has no draft `<Table>_Localised` row in a locale, that locale takes both stages from the first locale in its Fluent fallback chain (`Fluent_FallbackLocale`, in `Sort` order) that has one: that locale's draft area, and its live area when it has a `<Table>_Localised_Live` row — the blocks the old site showed there. A locale with a draft row of its own owns both stages: when it has no live row, the translation was never published and gets no live blocks, never its fallback's. The copy is independent: it gets its own records, and editing the source locale's blocks afterwards leaves it unchanged. The log names the locale that supplied it. When no locale in the chain has a row for the page, the page gets no grid in that locale.

**`UseGrid` is a shared page flag.** It is excluded from Fluent localisation in the module's `_config/fluent.yml` and is set once on the page record — not per locale — when the migration completes successfully.

> **Sites converted with an earlier release are not repaired.** Before per-locale areas were recognised, such a site was converted as single-language: only the default locale got a grid, filled from whichever locale's area was saved last. Re-running the task does not correct that: every page that already has Sections in a locale is skipped as already migrated. To convert such a page again, delete its Section tree in the affected locales first (see [Idempotency and Re-running](#idempotency-and-re-running)).

### Not the same as `AssignGridLocaleTask`

`migrate-grid-with-fluent` reads *legacy Elemental tables* and writes fully-localised grid trees from scratch. The `AssignGridLocaleTask` recipe in [Fluent — enabling Fluent on existing grid content](fluent.md#enabling-fluent-on-existing-grid-content) does something different: it re-localises grid data that was already migrated while locale-blind (`LocaleID = 0`). Use that one only if you enabled Fluent *after* the grid content already existed.

## What Gets Migrated

- **Pages whose current type still carries their content blocks.** Converting a page to another type in the CMS changes only its type: the old type's data stays in the database, including its link to the old content blocks. A page is migrated only when its current (draft) type is the type whose table holds that link, or a subclass of it — so a page converted between two types that share their blocks through a common parent type (both extending `Page`, with the blocks on `Page`) is migrated as usual, under its current type. A page converted to a type outside that line is skipped on both stages and listed (see [Summary line](#summary-line)). Redirector pages and virtual pages are never migrated, nor any type built on them; a site can add its own page types to that list:

  ```yaml
  ---
  Name: app-grid-migration
  ---
  WeDevelop\Grid\Migration\Service\LegacyPageDiscovery:
    excluded_page_classes:
      - App\Pages\SearchResultsPage
  ```

  Pages deleted from the site tree, pages with `UseElementalGrid = 0` and pages without a content area are left out without being listed, as before. A page whose type no longer exists in the code base is still migrated.
- **Hierarchy.** The flat legacy list becomes Section → Row → Column → Content.
- **Grid settings.** The 15 legacy per-viewport fields (`SizeXS`…`SizeXL`, `OffsetXS`…`OffsetXL`, `VisibilityXS`…`VisibilityXL`) are converted into the new `GridSettings` value object (`default` + per-viewport `overrides`). An override is only emitted when it differs from the default.
- **Grid settings clamping.** Invalid legacy values are clamped to keep the write transaction alive:
  - `width` is clamped to `[1, columnCount]`.
  - `offset` is clamped to `[0, columnCount - 1]`, then reduced further if `width + offset > columnCount`.
  - `Size = 0` is treated as "not set" and promoted to the full column width (required for plain `dnadesign/silverstripe-elemental` sites where Size columns do not exist).
  - Every clamp is logged as a warning so you can review the migration log and decide whether to correct data in the CMS.
- **Element metadata.** `Title`, `ShowTitle`, `TitleClass`, `ExtraClass`, `Sort`. `TitleTag` defaults to `h2` when the legacy value is empty.
- **Row metadata.** `ElementRow.Title` and `ElementRow.ExtraClass` map to the new `Row`. `ElementRow.CustomSectionClass` maps to the parent `Section.ExtraClass`. Each stage gets the values of its own legacy row: the live Row and Section carry the published row's values, not the draft ones. A row published without a title gets the `Row N` default for its position on live, even when its draft row was titled. Your own row fields can be carried across too — see [hooks 6–9](#6-mapping-your-own-row-settings-onto-rows).
- **Content body.** `ElementContent.HTML` is copied directly onto `ContentElement.HTML` on both draft and live.
- **Media fields.** Fields added by `ElementContentExtension` (`ContentColumns`, `ContentVerticalAlign`, `ExtraColumnGap`, `MediaType`, `MediaCaption`, `MediaRatio`, `MediaPosition`, `MediaImage`, video fields) are translated into the equivalent fields on `BlockMediaExtension` — including CSS-class → enum conversions and a discrete scale mapping for `ExtraColumnGap`. Each field goes to the table of the target class that holds it; a field no table holds is skipped and reported at the end of the run (see [Before You Begin](#before-you-begin)).
- **Draft and live stages.** The migration writes the draft hierarchy first, then reconciles the live stage. Elements that exist on both stages reuse the draft IDs; elements that only exist on live get new records on both stages to preserve Versioned integrity.
- **`UseGrid` flag.** Pages that successfully migrate get `UseGrid = true` so `GridPageExtension` renders the new grid editor immediately. Legacy pages that had `UseElementalGrid = false` on the old extension are carried over as `UseGrid = false` (and are not migrated structurally) so projects with the per-page editor toggle (`enable_editor_toggle: true`) keep their opt-out. The `UseGrid` write happens inside the same transaction as the grid hierarchy writes so partial failures never leave a page flagged as grid-enabled without content.

## What Is NOT Migrated

- `ElementRow.IsFluid`. This was a per-row flag in the old module and has no per-instance equivalent in the new Section model.
- `ElementRow.TitleTag` and `ElementRow.TitleClass`.
- The legacy `Element` / `ElementRow` / `ElementContent` / `ElementalArea` rows themselves. They are left in the database for manual cleanup.
- Custom element subclasses that are not configured via an extension hook.
- Project row settings (your own `ElementRow` columns) that no row or section hook maps — see [hooks 6–9](#6-mapping-your-own-row-settings-onto-rows).
- Rows whose structure differs between draft and live. The Rows and Sections are built from the draft layout; a row the live area does not have keeps its draft values on live, and a row that only the live area has goes through the live-only path (appended after the draft content).

## One Column per Block

Every legacy content block becomes its own `Column`, carrying that block's own width, offset and visibility. Blocks are never combined into a shared Column — not even consecutive blocks with identical settings, and not full-width blocks either. A row of blocks therefore keeps its legacy side-by-side layout:

```
BEFORE — three legacy blocks at one third width

ElementRow "Team row"
├── ElementContent "Team member A"  (md=4)
├── ElementContent "Team member B"  (md=4)
└── ElementContent "Team member C"  (md=4)

AFTER — three Columns, left to right

Row
├── Column  md=4  →  Content "Team member A"
├── Column  md=4  →  Content "Team member B"
└── Column  md=4  →  Content "Team member C"
```

The original order is always preserved. When the widths in a row add up to more than the grid's column count (say 1/3, 1/2, 1/3, or six blocks at one third), the Columns that do not fit wrap to a new line — the grid adapters already lay rows out that way, so the result matches the legacy page.

Things to be aware of:

- **Full-width blocks each get their own Column wrapper.** Two consecutive `md=12` blocks become two full-width Columns, one below the other. Vertical margins your theme puts on the blocks no longer collapse between them, so spacing between stacked full-width blocks can grow.
- **Offsets on Tailwind targets.** Legacy offsets are copied as-is. Tailwind reads an offset as an absolute column start (`col-start-N`) rather than a gap after the previous Column, so a Column with an offset that sits after another Column in the same Row drops to a new line. Giving every block its own Column makes this more common than before. Translating legacy offsets into Tailwind start positions is planned as a follow-up; until then, review rows that combine several Columns with offsets.
- **Pages migrated with an earlier release are not changed.** Releases before this behaviour combined consecutive blocks with identical settings into one Column. The migration only runs once per page (see below), so those pages keep their combined Columns.

## Idempotency and Re-running

The migration skips any page that already has at least one `Section` on draft for the target `--zone`. This makes it safe to re-run after fixing an extension or adding `--page-ids` to retry a failed subset.

A partial migration (draft written, live reconciliation failed) will not be retried automatically — the idempotency check only considers draft. If you need to redo a page, delete the new Section tree for that page first.

## Customising the Migration

Ten extension points are available via `SilverStripe\Core\Extensible`. Register an `Extension` (`SilverStripe\Core\Extension`) on the service or task class listed in each section; hook 10 fires on the migrated block itself.

### 1. Custom element class mapping

`DraftHierarchyWriter` calls `updateClassNameMapping($newClassName, $oldClassName)` before instantiating each new element. Use this to redirect legacy subclasses to their new-world equivalents.

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
WeDevelop\Grid\Migration\Service\DraftHierarchyWriter:
  extensions:
    - App\Migration\MyMigrationExtension
```

The resolved class **must** extend `WeDevelop\Grid\Model\GridElement`, otherwise the migration aborts the page with a `RuntimeException`.

### 2. Post-processing individual elements

`DraftHierarchyWriter` also calls `updateElementFieldMapping($newElement, $legacyElement)` after the default field mapping has been applied. Use this to copy subclass-specific fields that the default mapper does not know about.

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

> **`updateElementFieldMapping` runs on the DRAFT build only.** For an element that
> exists on both stages, the migration publishes the draft record with
> `writeToStage(LIVE)` and then corrects the live row's *stock* fields from the live
> legacy element. Fields you set in `updateElementFieldMapping` were read from the
> draft legacy element, so wherever the two stages diverge, the draft value would go
> live. Use `updateLiveElementFieldMapping` (below) to reconcile them.

### 3. Reconciling custom fields on the live stage

`LivePublisher` calls `updateLiveElementFieldMapping($element, $liveElement)` for
every element shared between draft and live, right after the stock live fields have
been corrected. `$liveElement` is the **live** `LegacyElement`.

Write to the LIVE stage only — `$element` is the draft record, so a plain `write()`
would push live values back onto draft:

```php
public function updateLiveElementFieldMapping(
    \WeDevelop\Grid\Model\GridElement $element,
    \WeDevelop\Grid\Migration\DTO\LegacyElement $liveElement,
): void {
    if (!$element instanceof \App\Grid\TeaserElement || $liveElement->mediaData === null) {
        return;
    }

    \SilverStripe\ORM\DB::prepared_query(
        'UPDATE "App_Grid_TeaserElement_Live" SET "TeaserLinkURL" = ? WHERE "ID" = ?',
        [(string) ($liveElement->mediaData->fields['TeaserLinkURL'] ?? ''), $element->ID],
    );
}
```

Register the extension on `WeDevelop\Grid\Migration\Service\LivePublisher`.

Live-**only** elements need no equivalent: they are built from the live legacy data
by `DraftHierarchyWriter`, so `updateElementFieldMapping` already sees live values
for them.

### 4. Filtering or augmenting the legacy element list

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

### 5. Overriding the FieldMapper lookup tables

`AbstractMigrationTask` calls `updateFieldMapperConfig($classNameMap, $verticalAlignMap, $mediaPositionMap, $gapSizeMap)` immediately before constructing the `FieldMapper`. Each argument is a nullable array passed by reference; populating one replaces the corresponding built-in Bootstrap default, leaving the untouched ones on the defaults. Use this when your legacy `ElementContentExtension` was customised to store non-standard CSS class values (the default `silverstripe-elemental-grid` install stores Bootstrap strings regardless of the rendering framework, so most sites need no override).

```php
use SilverStripe\Core\Extension;

class MyFieldMapperExtension extends Extension
{
    /**
     * @param array<string, string>|null $classNameMap
     * @param array<string, string>|null $verticalAlignMap
     * @param array<string, string>|null $mediaPositionMap
     * @param array<int, int>|null       $gapSizeMap
     */
    public function updateFieldMapperConfig(
        ?array &$classNameMap,
        ?array &$verticalAlignMap,
        ?array &$mediaPositionMap,
        ?array &$gapSizeMap,
    ): void {
        $verticalAlignMap = [
            '' => 'top',
            'items-center' => 'center',
            'items-end' => 'bottom',
        ];
    }
}
```

Register it in YAML against the concrete task(s) you run. The hook is inherited from `AbstractMigrationTask`, so registering on the abstract class covers both bundled tasks:

```yaml
WeDevelop\Grid\Migration\Task\AbstractMigrationTask:
  extensions:
    - App\Migration\MyFieldMapperExtension
```

For field-level tweaks on individual element subclasses, `updateElementFieldMapping` (option 2 above) remains the right hook — it runs after the default mapping and lets you overwrite specific fields on the new element.

### 6. Mapping your own row settings onto Rows

A legacy site often added its own fields to `ElementRow` — a background colour, a
row image, a "card row" switch. `DraftHierarchyWriter` calls
`updateRowFieldMapping(Row $row, LegacyElement $legacyRow)` for each new `Row`,
right before writing it. `$legacyRow` is the legacy row delimiter the Row is built
from; its `extraData` holds every column of that stage's legacy `Element` +
`ElementRow` record, your own columns included. Set the values on `$row`; the
writer writes it. On a Fluent site the hook runs once per locale, and `extraData`
holds that locale's values (see [What differs per locale](#what-differs-per-locale)).

```php
use SilverStripe\Core\Extension;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;

class RowSettingsMigrationExtension extends Extension
{
    public function updateRowFieldMapping(Row $row, LegacyElement $legacyRow): void
    {
        $row->BackgroundColour = (string) ($legacyRow->extraData['BackgroundColour'] ?? '');
        // A has_one copies as its ID. Publishing the file is the project's job.
        $row->RowImageID = (int) ($legacyRow->extraData['RowImageID'] ?? 0);
    }
}
```

```yaml
WeDevelop\Grid\Migration\Service\DraftHierarchyWriter:
  extensions:
    - App\Migration\RowSettingsMigrationExtension
```

No row or section hook fires for a container without a legacy row: the elements
before a page's first row form a Row (and, under `--strategy=sections`, a Section)
of their own, and none of hooks 6–9 see it. The same holds for a project strategy
that builds `MigrationRow` / `MigrationSection` DTOs without a legacy row — pass
the group's row as the `legacyRow` constructor argument to keep these hooks working.

The fields you map onto, and the mapping itself, must exist before the first run:
the migration skips a page that already has a Section on draft (see
[Idempotency](#idempotency-and-re-running)), so a field or mapping added afterwards
does not reach pages already migrated.

### 7. Mapping your own row settings onto Sections

`DraftHierarchyWriter` calls
`updateSectionFieldMapping(Section $section, LegacyElement $legacyRow)` for each new
`Section`, right before writing it, with the same kind of `$legacyRow` as hook 6.
Which legacy row a Section gets depends on the strategy: with `--strategy=sections`
each Section gets the row it was made from; with `--strategy=single-section` the
page's one Section gets the **first** explicit row — the row its `ExtraClass` comes
from.

```php
use WeDevelop\Grid\Model\Section;

public function updateSectionFieldMapping(Section $section, LegacyElement $legacyRow): void
{
    $section->Style = (bool) ($legacyRow->extraData['IsCardRow'] ?? false) ? 'card' : '';
}
```

Register it on `WeDevelop\Grid\Migration\Service\DraftHierarchyWriter`, like hook 6
(one extension class can carry both).

### 8. Reconciling your own row settings on the live Row

Like `updateElementFieldMapping`, hooks 6 and 7 run on the DRAFT build only: the
published Row and Section start as a `writeToStage(LIVE)` copy of the draft record.
Once the stock live values are in place (Row `Title` and `ExtraClass`, Section
`ExtraClass` from the live `CustomSectionClass`), `LivePublisher` calls
`updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow)`. `$liveRow` is the
**live** legacy row, its `extraData` read from `Element_Live` + `ElementRow_Live`.

`$row` is the draft record. Write to the LIVE stage only; a plain `write()` would
push live values back onto draft:

```php
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Model\Row;

class LiveRowSettingsMigrationExtension extends Extension
{
    public function updateLiveRowFieldMapping(Row $row, LegacyElement $liveRow): void
    {
        DB::prepared_query(
            'UPDATE "WeDevelop_Grid_Row_Live" SET "BackgroundColour" = ?, "RowImageID" = ? WHERE "ID" = ?',
            [
                (string) ($liveRow->extraData['BackgroundColour'] ?? ''),
                (int) ($liveRow->extraData['RowImageID'] ?? 0),
                $row->ID,
            ],
        );
    }
}
```

```yaml
WeDevelop\Grid\Migration\Service\LivePublisher:
  extensions:
    - App\Migration\LiveRowSettingsMigrationExtension
```

A field lives in the table of the class its extension is applied to (here
`WeDevelop_Grid_Row`); `DataObject::getSchema()->tableForField(Row::class, 'BackgroundColour')`
resolves it.

A live hook fires only for a container that is on live and whose legacy row is on
the live area too; a row the live area lacks keeps its draft values on live. A
live-**only** row needs no live hook: its Row and Section are built from the live
data by `DraftHierarchyWriter`, so hooks 6 and 7 already see live values for them.
A hook that throws rolls the page back, like the element hooks.

### 9. Reconciling your own row settings on the live Section

`LivePublisher` calls
`updateLiveSectionFieldMapping(Section $section, LegacyElement $liveRow)` for each
published Section, under the same rules as hook 8. Under
`--strategy=single-section`, `$liveRow` is the live copy of the first explicit row.

```php
use WeDevelop\Grid\Model\Section;

public function updateLiveSectionFieldMapping(Section $section, LegacyElement $liveRow): void
{
    DB::prepared_query(
        'UPDATE "WeDevelop_Grid_GridElement_Live" SET "Style" = ? WHERE "ID" = ?',
        [(bool) ($liveRow->extraData['IsCardRow'] ?? false) ? 'card' : '', $section->ID],
    );
}
```

`Style` is a column of the base `WeDevelop_Grid_GridElement` table. Register the
extension on `WeDevelop\Grid\Migration\Service\LivePublisher`, like hook 8.

### 10. Re-attaching items a block owns

A legacy slider owns its slides, a gallery its images, a card block its cards: each
item points at the legacy block's ID. The migrated block has a new ID, so those
items must be re-pointed to it. Right after a new element is written to a stage,
the migration calls `onAfterMigrate(LegacyElement $legacy, MigratedElementContext $context)`
on **that element**, through `invokeWithExtensions()`. Implement it on your own block
class, or on an Extension of it — not on a migration service.

It fires once per stage the element is written to: on draft for every element, and
on live for every element that is on the live page, live-only elements included.
`$context` (`WeDevelop\Grid\Migration\DTO\MigratedElementContext`) carries:

| Property | Value |
|----------|-------|
| `stage` | `MigratedElementContext::DRAFT` or `MigratedElementContext::LIVE`: the stage just written |
| `pageId`, `legacyAreaId` | The page being migrated and the legacy `ElementalArea` the element was read from. On a site with a separate area per locale and stage, that is the live area for an element read from the live page — a live-only element reports it on both stages |
| `locale` | The Fluent locale pass, `null` outside `migrate-grid-with-fluent` |
| `sharedWithPageIds` | The other pages that use the same legacy area; empty when it is not shared |
| `firstCopy` | `null` when this element is the first written for `$legacy`. Otherwise the element an earlier page (shared area) or locale pass wrote for it, in this run or an earlier one — the one that took the items |

The first copy re-points the legacy block's items, on each stage's table; every later
copy copies the first copy's items, each stage from that stage's row. With a `has_many` from the block to a
versioned `Slide` (`has_one Slider`):

```php
use App\Model\Slide;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use WeDevelop\Grid\Migration\DTO\LegacyElement;
use WeDevelop\Grid\Migration\DTO\MigratedElementContext;

class SliderMigrationExtension extends Extension
{
    public function onAfterMigrate(LegacyElement $legacy, MigratedElementContext $context): void
    {
        $slider = $this->getOwner();
        $firstCopy = $context->firstCopy;

        if ($firstCopy === null) {
            // Re-point the legacy block's slides on the stage just written.
            $table = DataObject::getSchema()->tableName(Slide::class);
            if ($context->stage === MigratedElementContext::LIVE) {
                $table .= '_Live';
            }

            DB::prepared_query(
                \sprintf('UPDATE "%s" SET "SliderID" = ? WHERE "SliderID" = ?', $table),
                [$slider->ID, $legacy->id],
            );

            return;
        }

        // A later copy copies the first copy's slides once, on the draft call,
        // each stage from that stage's row: draft may hold unpublished edits,
        // and a slide may be on one stage only.
        if ($context->stage !== MigratedElementContext::DRAFT) {
            return;
        }

        $slides = [
            Versioned::LIVE => Versioned::get_by_stage(Slide::class, Versioned::LIVE)->filter('SliderID', $firstCopy->ID),
            Versioned::DRAFT => Versioned::get_by_stage(Slide::class, Versioned::DRAFT)->filter('SliderID', $firstCopy->ID),
        ];
        $slideIds = \array_unique([...$slides[Versioned::LIVE]->column('ID'), ...$slides[Versioned::DRAFT]->column('ID')]);
        foreach ($slideIds as $slideId) {
            $copy = null;
            // Live first: a write to live writes draft too.
            foreach ($slides as $stage => $stageSlides) {
                $slide = $stageSlides->byID($slideId);
                if ($slide === null) {
                    continue;
                }

                $copyId = $copy?->ID ?? 0;
                $copy = $slide->duplicate(false);
                $copy->ID = $copyId;
                $copy->SliderID = $slider->ID;
                $copy->writeToStage($stage);
            }

            if ($copy !== null && $slides[Versioned::DRAFT]->byID($slideId) === null) {
                $copy->deleteFromStage(Versioned::DRAFT);
            }
        }
    }
}
```

```yaml
App\Grid\SliderBlock:
  extensions:
    - App\Migration\SliderMigrationExtension
```

The "parent = legacy ID" match is exact because new element IDs start above every
legacy one (see [Before You Begin](#before-you-begin)): a slide still pointing at a
legacy number was not moved, also when page 1's new slider has the number page 2's
legacy gallery had.

Rules for an implementation:

- It runs inside the page's transaction. Use the default database connection (`DB::`
  or the ORM), issue no DDL (it would commit the page half-way), and touch no files:
  a file change does not roll back with the page.
- It may throw. The page then rolls back, its moved items with it, and is reported as
  failed like any other page failure. The items still point at the legacy number, so
  a re-run after the fix attaches them.
- The recipe re-points the base and `_Live` rows only. The items' version history
  (`Slide_Versions`) keeps the legacy parent.
- On a Fluent site each locale pass commits on its own, the default locale first. When
  a later pass fails, the earlier locales stay migrated and re-attached; a re-run
  retries the failed locale only (it has no Sections yet), and its copy is handed the
  earlier locale's as `firstCopy`.
- Which element is a legacy element's first copy is stored in
  `WeDevelop_Grid_MigratedFirstCopy`, written with each page, so a copy written by a
  later run (`--page-ids`, a re-run after a failure) is handed the first copy an
  earlier run wrote. Deleting a migrated page's Sections to migrate it again does not
  clear those rows: the new copy is handed the deleted one, which is not found, so
  `firstCopy` is `null` and the items the deleted copy took stay with it.

## After Migration

1. Run `sake db:build --flush` to make sure the schema is consistent.
2. Run `sake tasks:repair-grid-zone --dry-run`. Every migrated root should already carry its zone, so this is a check: it should report nothing to repair. If it lists rows, run it without `--dry-run`, and resolve what it leaves for manual review — a page holding such a row cannot be published until then.
3. Spot-check a handful of migrated pages in the CMS and on the frontend. Pay particular attention to:
   - Section and Row titles (Sections are always created untitled).
   - Rows with more Columns than fit on one line — they wrap, as on the legacy page.
   - Stacked full-width blocks, which now each sit in their own Column, so theme margins between them no longer collapse.
   - On Tailwind targets, Columns with an offset that follow another Column in the same Row (they drop to a new line).
   - Media blocks that relied on Bootstrap CSS classes for alignment or ordering.
4. Review the log output for `warning` entries — each one corresponds to a clamp, an unresolved mapping, or a `customSectionClass` conflict resolved by Strategy B.
5. Once you are satisfied, drop the legacy tables manually (`Element`, `Element_Live`, `Element_Versions`, `ElementRow`, `ElementRow_Live`, `ElementContent`, `ElementContent_Live`, `ElementalArea`, `ElementalArea_Live`, any `*_Localised` tables of a Fluent site, any legacy subclass tables, and a hand-added `BaseElement` copy if the site has one) in a separate migration step. Empty `WeDevelop_Grid_MigratedFirstCopy` too once no page or locale is left to migrate; the table itself belongs to the module and comes back on `db:build`.

## Related Migrations

### Converting `gorriecoe/silverstripe-link` to `silverstripe/linkfield`

Many SS4/SS5 sites store element link fields with `gorriecoe/silverstripe-link`. On SS6 those become `silverstripe/linkfield` links. This conversion is **not** part of the grid migration — it is owned by `silverstripe/linkfield`, which ships its own `SilverStripe\LinkField\Tasks\GorriecoeMigrationTask`. The only thing that matters for the grid is the **ordering**:

- Run `GorriecoeMigrationTask` **after** the grid migration. Block relations a project re-attaches through [hook 10](#10-re-attaching-items-a-block-owns) are already on the new element IDs by then.
- The task **preserves Link IDs**, so any link foreign keys copied onto migrated content elements (for example a block's `PrimaryLinkID`) keep resolving once the links are converted.
- Running it **before** the grid migration leaves the grid pass copying link FKs that point at rows the link conversion later moves, so button labels render as placeholders.

Carry the block link FK fields across during the grid pass with the `updateElementFieldMapping` hook (see [Customising the Migration](#customising-the-migration)), then run the link conversion last.

## Troubleshooting

**"Legacy element table "Element" not found."** — The database does not hold the elemental tables of a site on the supported source (see [Requirements](#requirements)), so the task refuses before the banner and the confirmation prompts and changes nothing. The message names every missing table (`Element`, `Element_Live`). Check that you run the task against the upgraded copy of the SS5 database, and that `dev/build` did not rename the tables to `_obsolete_*`; rename them back if it did.

**"Legacy element table "Element_Versions" not found; new grid elements cannot be numbered above the legacy ones."** — A run that writes raises the new element numbering above every legacy ID first (see [Before You Begin](#before-you-begin)) and reads all three legacy element tables for it. It stops before the first page and changes nothing. Restore the table from the SS5 database, or rename it back from `_obsolete_*`.

**"Page table "Page": dev/build set aside its per-locale ElementalAreaID. Rename "_obsolete_Page_Localised" back to "Page_Localised" …"** — `migrate-grid-with-fluent` found the per-locale area of that page type, for draft or live, only in a table `dev/build` set aside. It refuses before the confirmation prompts and changes nothing, under `--dry-run` too. The message names every table still set aside, so after renaming only `_obsolete_Page_Localised` back it still refuses, naming `_obsolete_Page_Localised_Live`. Rename the tables back as the message says (see [Additional prerequisites](#additional-prerequisites)) and run the task again.

**A page is listed under "Skipped pages" but should have been migrated.** — Its old content is left untouched. If its type is on the list of types never migrated, take the type off the list and migrate that page again with `--page-ids`. YAML merges lists, so it cannot remove the default `RedirectorPage` or `VirtualPage`; set the whole list in `app/_config.php` instead:

```php
use SilverStripe\CMS\Model\VirtualPage;
use WeDevelop\Grid\Migration\Service\LegacyPageDiscovery;

LegacyPageDiscovery::config()->set('excluded_page_classes', [VirtualPage::class]);
```

A page skipped because its current type no longer has the old content blocks is migrated once its type is changed back to the type the reason names, or to one built on it.

**"Page X already migrated for zone Y, skipping."** — The idempotency check found at least one `Section` for that page and zone on draft. If you need to redo the page, delete its new Section tree first.

**"Resolved class … does not extend GridElement."** — A legacy element class has no mapping to a new class that extends `WeDevelop\Grid\Model\ContentElement` / `GridElement`. Register an `updateClassNameMapping` extension (option 1) or update the PHP class hierarchy.

**Media blocks show the wrong alignment or order.** — The default CSS-class lookups expect Bootstrap values (`align-items-center`, `order-1 order-md-2`, …). Register an `updateFieldMapperConfig` extension (option 5 above) to supply your own lookup tables, or use `updateElementFieldMapping` (option 2) for per-element fixes after the default mapping has run.

**Viewport overrides are missing after migration.** — The automatic viewport-key mapping is case-insensitive but requires at least a case-insensitive match between legacy keys (`XS`, `SM`, `MD`, `LG`, `XL`) and the active adapter's viewport keys. Migrating to an adapter with different names (for example a custom adapter keyed `mobile`, `tablet`, `desktop`) requires an explicit `--viewport-map` argument.

**Draft-deleted content reappeared on draft.** — This is expected. Live-only elements are recreated on both stages to preserve Versioned integrity.

**"SS_GRID_ADAPTER environment variable is not set" (or "Invalid SS_GRID_ADAPTER value …").** — The active grid adapter is selected from the required `SS_GRID_ADAPTER` environment variable and there is no default. The container throws this `RuntimeException` at boot, so it surfaces during `dev/build` and on every migration task before any data is read or written. Set `SS_GRID_ADAPTER` to a preset (`bootstrap`, `tailwind`) or an FQCN implementing both `GridAdapterInterface` and `ContentLayoutAdapterInterface` — see [Requirements](#requirements) — then re-run the task.

## See also

- [The grid editor](usage/grid-editor.md) — what migrated content looks like in the CMS afterwards
- [Fluent (multi-locale) support](fluent.md) — installing and configuring Fluent alongside the grid
- [Grid Adapter System](architecture/grid-adapter.md) — viewport keys and column counts, which `--default-viewport` and `--viewport-map` are matched against
