# Fluent (Multi-Locale) Support

This module supports optional multi-locale content via [silverstripe-fluent](https://github.com/tractorcow-farm/silverstripe-fluent). When enabled, each locale maintains its own independent grid structure — sections, rows, columns, and content elements are fully isolated per locale.

## Requirements

- A version of `tractorcow/silverstripe-fluent` compatible with your SilverStripe 6 install. The module only references Fluent types at runtime when the class exists (the `_config/fluent.yml` hooks are gated by `classexists`), so no hard composer constraint is pinned. Use the latest Fluent release that supports your SilverStripe version.

## Setup

Install Fluent (use the Fluent version that matches your SilverStripe release):

```bash
composer require tractorcow/silverstripe-fluent
```

Add the following YAML config to your project (e.g. `app/_config/grid-fluent.yml`):

```yaml
---
Name: project-grid-fluent
Only:
  classexists: TractorCow\Fluent\Extension\FluentIsolatedExtension
---
WeDevelop\Grid\Model\GridElement:
  extensions:
    FluentIsolated: TractorCow\Fluent\Extension\FluentIsolatedExtension
```

Then run a dev/build:

```bash
vendor/bin/sake dev/build flush=1
```

## How It Works

- **Query filtering**: All `GridElement` queries are automatically scoped to the current locale. Elements from other locales are not visible.
- **Auto-locale assignment**: When a new element is written, Fluent automatically sets its `LocaleID` to the current locale.
- **Auto-scaffolding**: In normal CMS use, the Section → Row → Column scaffolding chain runs per locale — creating a Section in a locale produces a full tree scoped to that locale. The Elemental migration tool disables auto-scaffolding (`auto_scaffold: false`) during its run because it constructs the hierarchy bottom-up itself.
- **Publishing**: Versioned publishing works per locale. Draft and live stages are independent within each locale.
- **Deletion**: Deleting an element in one locale does not affect elements in other locales.

## Copy and Clear Behavior

When Fluent is installed, the module automatically handles two CMS operations:

- **Copy to locale**: When a page is copied to a new locale (via CMS "Copy to other locales" or `CopyToLocaleService`), the entire grid hierarchy is duplicated into the target locale. The source locale's tree is unchanged.
- **Clear from locale**: When a locale is cleared from a page, all grid elements in that locale are deleted (cascade through Section → Row → Column → Content). Other locales are unaffected.

This is handled by `FluentGridPageExtension` (applied to SiteTree) and `GridAwareDeleteLocalisationPolicy` (registered in place of Fluent's `DeleteLocalisationPolicy` via DI). Both are registered automatically in `_config/fluent.yml` when Fluent is installed.

**Note:** `GridAwareDeleteLocalisationPolicy` is a wrapper — it delegates to Fluent's original `DeleteLocalisationPolicy` first and then handles grid element cleanup on top. Standard Fluent behavior is preserved for all non-grid DataObjects.

## Important Warning

Do **NOT** configure `apply_isolated_locales_to_admin: false` under `WeDevelop\Grid\Model\GridElement` in YAML. That Fluent setting disables locale filtering in the CMS admin, which would cause all locales' grid elements to appear together and break the tree structure.

## Migrating Elemental content under Fluent

Use the dedicated `migrate-grid-with-fluent` task to migrate legacy `dnadesign/silverstripe-elemental` (or `wedevelopnl/silverstripe-elemental-grid`) content into the new grid on a Fluent site. The plain `migrate-grid` task **refuses to run** when localised legacy tables are detected and directs you here instead.

### How the task works

The task migrates per locale: the default locale is migrated first, then each additional locale in turn, each inside a `FluentState` context, writing isolated per-locale `GridElement` trees.

**Auto-detection of the legacy localisation model.** The task inspects the database table shape to determine which Fluent strategy was used in the SS5 site:

| Legacy table shape | Detected model | Behaviour |
|--------------------|----------------|-----------|
| `BaseElement_Localised` present | Field-localised | Shared element structure; per-locale content read from `*_Localised` tables and overlaid onto the base row. |
| `BaseElement.LocaleID` column present | Isolated | Separate element rows exist per locale; each locale's rows are filtered by `LocaleID`. |

> **Isolated model prerequisite — Locale IDs must be stable.** The migration filters legacy element rows by `LocaleID`, treating those integer values as equal to the current Fluent `Locale` record IDs. This assumption holds for a standard in-place SilverStripe 5 → 6 upgrade, where the same database is carried forward and the `Locale` table rows retain their original IDs. **If the `Locale` records were deleted and recreated between the time the legacy content was authored and when you run the migration, the IDs will differ, and the Isolated per-locale filter may select the wrong content or return no results at all.** Verify that your `Locale` table IDs are unchanged before running `migrate-grid-with-fluent` on an Isolated site.
| Neither | Single-locale | No locale-specific data; elements are migrated once into the default locale only. |
| Both | Unsupported | Ambiguous mixed configuration — the task aborts with an error. |

### Layout vs content

**Layout is locale-invariant.** Column grouping and `Size`/`Offset`/`Visibility` grid settings are derived from the base element rows and applied equally across all locales. Per-locale layout differences present in the legacy data are not honoured — this is an accepted limitation of a one-shot migration.

Only content fields (`Title`, `HTML`, media text) differ per locale.

### Untranslated elements

Elements in the field-localised model that have no `*_Localised` row for a given locale are migrated using their base content. This matches Fluent's render-time fallback behaviour and ensures no content is silently omitted.

### `UseGrid` flag

`UseGrid` is treated as a non-localised shared page flag. It is excluded from Fluent localisation in this module's `_config/fluent.yml` and is set once on the page record (not per locale) when migration completes successfully.

### Distinction from `AssignGridLocaleTask`

This migration is distinct from the post-migration `AssignGridLocaleTask` recipe described in [Migration from Existing Data](#migration-from-existing-data) below. `AssignGridLocaleTask` re-localises already-migrated, locale-blind grid data (records with `LocaleID = 0`). `migrate-grid-with-fluent` is for migrating from a legacy `dnadesign/silverstripe-elemental` source — it reads legacy tables directly and writes fully-localised `GridElement` trees from scratch.

### Prerequisites

- **The legacy `BaseElement_Localised` / `*_Localised` (and `_Live`) tables must still be present.** If `dev/build` moved them to `_obsolete_*` after the old element relation was dropped, rename them back before migrating — the migration reads legacy tables by name.
- The source site must be a working SilverStripe 5 site. See the [source-version prerequisite](migration.md#requirements) in the migration guide.

Run the task via CLI:

```bash
vendor/bin/sake dev/tasks/migrate-grid-with-fluent \
    --default-viewport=MD --zone=main --strategy=sections --dry-run
```

The task accepts the same options as `migrate-grid` (see the [Options Reference](migration.md#options-reference)).

## Migration from Existing Data

If you enable Fluent on a site with existing grid content, those records will have `LocaleID = 0` and become invisible in all locales. You must assign a locale to existing records. (If you are coming from an installation that ran Fluent previously, verify your orphaned records actually have `LocaleID = 0` before running this task — records created under a different setup may use other sentinel values.)

Create a `BuildTask` in your project to assign the default locale to unassigned elements:

```php
use SilverStripe\Dev\BuildTask;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Model\GridElement;

class AssignGridLocaleTask extends BuildTask
{
    public function run($request): void
    {
        $default = Locale::getDefault();

        foreach (GridElement::get()->filter('LocaleID', 0) as $element) {
            $element->LocaleID = $default->ID;
            $element->write();
        }
    }
}
```

Run it via the CMS at `/dev/tasks/AssignGridLocaleTask` or via CLI:

```bash
vendor/bin/sake dev/tasks/AssignGridLocaleTask
```
