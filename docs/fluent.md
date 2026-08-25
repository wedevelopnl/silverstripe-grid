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
vendor/bin/sake db:build --flush
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

### Shared blocks

[Shared blocks](usage/shared-blocks.md) follow the same isolation model, with one wrinkle worth knowing:

- The block **record** is a single cross-locale row; its **subtree** is locale-isolated like any other element.
- Copying a **page** carries its placements — pointing at the same block record — and gives every block it places a subtree in the target locale if it has none. Translating a page therefore translates the blocks on it, which is why a freshly copied page renders rather than showing empty frames.
- Copying a **block** clones that block's subtree into the target locale.
- Placing a block into a locale that has no content for it localises it the same way, so an author working directly in a second locale can pick any block from the library.
- **Localising a block is additive and cross-page.** Blocks are shared: giving one content in a locale means every *other* page in that locale placing it starts rendering too. It can never overwrite a translation — the copy only ever runs into a locale that holds nothing, so a second page copy, or two pages placing the same block, add nothing.
- Clearing a locale removes that locale's block subtrees and that locale's placements; the block record survives. Clearing a locale from a **page** does not remove block subtrees — the block may serve other pages in that locale, so copy creates but clear does not destroy.
- A placement whose block has no content in the current locale still renders empty on the front end. That state is now reached by emptying a block after it was placed, rather than by copying a page without its blocks.

`SharedBlockLocaliser` holds the block-localisation logic and its idempotency guard; `FluentSharedBlockExtension`, `FluentGridPageExtension` and `SharedBlockService::place()` all go through it, and it shares clone mechanics with the page side via `LocalisedSubtreeCloner`.

This is handled by `FluentGridPageExtension` (applied to SiteTree), `FluentSharedBlockExtension` (applied to SharedBlock) and `GridAwareDeleteLocalisationPolicy` (registered in place of Fluent's `DeleteLocalisationPolicy` via DI). All three are registered automatically in `_config/fluent.yml` when Fluent is installed.

**Note:** `GridAwareDeleteLocalisationPolicy` is a wrapper — it delegates to Fluent's original `DeleteLocalisationPolicy` first and then handles grid element cleanup on top. Standard Fluent behavior is preserved for all non-grid DataObjects.

## Important Warning

Do **NOT** configure `apply_isolated_locales_to_admin: false` under `WeDevelop\Grid\Model\GridElement` in YAML. That Fluent setting disables locale filtering in the CMS admin, which would cause all locales' grid elements to appear together and break the tree structure.

## Migrating Elemental content under Fluent

Legacy `dnadesign/silverstripe-elemental` (or `wedevelopnl/silverstripe-elemental-grid`) content is migrated with the dedicated `migrate-grid-with-fluent` task — the plain `migrate-grid` task refuses to run on a Fluent site. That task, its locale-model auto-detection, and its limitations are documented in [Migration — Multi-locale (Fluent) sites](migration.md#multi-locale-fluent-sites).

## Enabling Fluent on existing grid content

If you enable Fluent on a site that *already* has grid content, those records will have `LocaleID = 0` and become invisible in every locale. You must assign a locale to them. (If you are coming from an installation that ran Fluent previously, verify your orphaned records really do have `LocaleID = 0` first — records created under a different setup may use other sentinel values.)

Create a `BuildTask` in your project to assign the default locale to unassigned elements:

```php
<?php

declare(strict_types=1);

namespace App\Tasks;

use Override;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use TractorCow\Fluent\Model\Locale;
use WeDevelop\Grid\Model\GridElement;

class AssignGridLocaleTask extends BuildTask
{
    protected static string $commandName = 'assign-grid-locale';

    protected string $title = 'Assign the default locale to locale-blind grid elements';

    protected static string $description = 'Sets LocaleID on grid elements left at 0 after enabling Fluent.';

    #[Override]
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $default = Locale::getDefault();

        if ($default === null) {
            $output->writeln('<error>No default locale is configured.</error>');

            return Command::FAILURE;
        }

        foreach (GridElement::get()->filter('LocaleID', 0) as $element) {
            $element->LocaleID = $default->ID;
            $element->write();
        }

        return Command::SUCCESS;
    }
}
```

`BuildTask` in SilverStripe 6 is a Symfony Console command: `execute()` is the abstract method to implement (the old `run($request)` signature is gone), and `$commandName` — not the class name — is what names the task on the CLI and in the URL.

Run it via the CMS at `/dev/tasks/assign-grid-locale` or via CLI:

```bash
vendor/bin/sake tasks:assign-grid-locale
```

## See also

- [Migration — Multi-locale (Fluent) sites](migration.md#multi-locale-fluent-sites) — migrating legacy Elemental content on a Fluent site
- [Backend architecture — Fluent integration](architecture/backend.md#fluent-integration-optional) — the extension and DI wiring behind the behaviour described here
