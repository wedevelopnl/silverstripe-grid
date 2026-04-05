# Fluent (Multi-Locale) Support

This module supports optional multi-locale content via [silverstripe-fluent](https://github.com/tractorcow-farm/silverstripe-fluent). When enabled, each locale maintains its own independent grid structure — sections, rows, columns, and content elements are fully isolated per locale.

## Requirements

- `tractorcow/silverstripe-fluent` ^8.0

## Setup

Install Fluent:

```bash
composer require tractorcow/silverstripe-fluent ^8.0
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
- **Auto-scaffolding**: The Section → Row → Column scaffolding chain runs per locale. Creating a Section in a locale produces a full tree scoped to that locale.
- **Publishing**: Versioned publishing works per locale. Draft and live stages are independent within each locale.
- **Deletion**: Deleting an element in one locale does not affect elements in other locales.

## Copy and Clear Behavior

When Fluent is installed, the module automatically handles two CMS operations:

- **Copy to locale**: When a page is copied to a new locale (via CMS "Copy to other locales" or `CopyToLocaleService`), the entire grid hierarchy is duplicated into the target locale. The source locale's tree is unchanged.
- **Clear from locale**: When a locale is cleared from a page, all grid elements in that locale are deleted (cascade through Section → Row → Column → Content). Other locales are unaffected.

This is handled by `FluentGridPageExtension` (applied to SiteTree) and `GridAwareDeleteLocalisationPolicy` (replaces Fluent's `DeleteLocalisationPolicy` via DI). Both are registered automatically in `_config/fluent.yml` when Fluent is installed.

**Note:** The module globally replaces `DeleteLocalisationPolicy` via Injector. The replacement delegates to the original policy first, then handles grid element cleanup. Standard Fluent behavior is preserved for all non-grid DataObjects.

## Important Warning

Do **NOT** set `apply_isolated_locales_to_admin: false` on `GridElement`. This flag disables locale filtering in the CMS admin, which would cause all locales' grid elements to appear together and break the tree structure.

## Migration from Existing Data

If you enable Fluent on a site with existing grid content, those records will have `LocaleID = 0` and become invisible in all locales. You must assign a locale to existing records.

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
