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

## Migrating Elemental Content under Fluent

The [Elemental migration tool](migration.md) is locale-aware when Fluent is installed (the `TractorCow\Fluent\State\FluentState` class is present). It migrates the **default locale first**, then each other locale in turn, and writes each locale's content inside that locale's `FluentState` so `FluentIsolatedExtension` stamps the correct `LocaleID`. No extra task options are needed — running either migration task migrates every locale.

How each locale's source area is resolved:

- **Per-locale area.** For each eligible page, the legacy `ElementalArea` for the locale is read from the page's Fluent companion table `<PageTable>_Localised` (`RecordID` + `Locale` → `ElementalAreaID`). This is where Fluent stored a localised `ElementalArea` has_one on the old site (e.g. `$localised_copy = ['ElementalArea']`).
- **Default-locale fallback.** When a page has no `_Localised` row for the locale, the base `ElementalAreaID` column is used **only** for the default locale. Non-default locales with no localised area are skipped (the page simply has no content in that locale).
- **Non-localised areas.** A page whose `<PageTable>_Localised` companion table does not exist at all is treated as non-localised: its base area is migrated **once**, into the default locale, and is not duplicated across locales.

### Prerequisite: keep the legacy `_Localised` tables

The migration reads the legacy tables by name. If your new page class drops the old Elemental relation, `dev/build` can move the legacy companion tables to `_obsolete_<PageTable>_Localised`. **Rename them back** (to `<PageTable>_Localised` and `<PageTable>_Localised_Live`) before migrating — otherwise the migration sees only base-locale content and your other locales come out empty. This rename is project-specific DBA work and is intentionally **not** performed by the module (it will not silently restructure your database).

Without Fluent installed the migration runs exactly as documented in [migration.md](migration.md) — a single pass against the base `ElementalArea`.

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
