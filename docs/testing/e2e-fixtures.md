# E2E Fixture Protocol

Playwright specs load their test data through a dev-only HTTP endpoint that writes named YAML fixtures into the database. The endpoint, loader, post-action system, and Playwright client are provided by the [`wedevelopnl/silverstripe-e2e`](https://packagist.org/packages/wedevelopnl/silverstripe-e2e) module (a dev dependency); the grid supplies its fixtures, the page-class allowlist, and auto-scaffold `config_overrides` via `_config/dev.yml`. This guide covers the fixture YAML schema, the post-action system, the controller's HTTP contract, and the conventions every fixture must follow.

## Architecture

```
Playwright spec
  │  await loadFixture('element-tree')
  │
  ▼
POST /dev/e2e-fixtures/load  (WeDevelop\E2e\Fixtures\FixtureController, dev-only)
  ├── canInit() + init() guard on Director::isDev()
  ├── Looks up name in FixtureLoader::$fixtures config
  └── Delegates to FixtureLoader::load()
       ├── reset()                  — deletes every record of the purge_classes
       ├── applyConfigOverrides()   — grid's config_overrides force auto_scaffold
       │                              off on Section/Row for the fixture write
       ├── YamlFixture::writeInto() — writes the YAML into the factory
       ├── applyPostActions()       — publish / modify / attach images
       └── Return FixtureResult     — { fixtureName, pageId, pageUrl, fixtureMap }
```

Every fixture run is fully idempotent: `load()` calls `reset()` first so re-running the same (or a different) fixture never stacks residue from a previous run.

## HTTP contract

### `POST /dev/e2e-fixtures/load`

Loads the named fixture. The fixture name is read from the **POST body** field `fixture` (`$request->postVar('fixture')`) — a query-string `?fixture=` is ignored and the request fails with `400`. From the shell:

```bash
curl -k -X POST -d "fixture=<name>" https://localhost:<WEB_PORT>/dev/e2e-fixtures/load
```

Returns:

```json
{
  "success": true,
  "fixture": "element-tree",
  "data": {
    "pageId": 42,
    "pageUrl": "/e2e-grid-test/",
    "fixtureMap": {
      "Page": {"e2e_page": 42},
      "WeDevelop\\Grid\\Model\\Section": {"section1": 108},
      ...
    }
  }
}
```

The `fixtureMap` lets specs look up fixture identifiers (`section1`, `leaf3`, …) to real database IDs, which the CMS URL routing needs.

Failure response: `400 { success: false, error: "..." }`.

### `POST /dev/e2e-fixtures/reset?confirm=1`

Deletes **every record of the `FixtureLoader.purge_classes` config in `_config/dev.yml`, and of their subclasses, on both stages** — currently `Page`, `WeDevelop\Grid\Model\GridElement` and `WeDevelop\Grid\Model\SharedBlock`. Ownership is declared, not inferred: nothing keys off a URL segment or off which records the fixture happened to write, so a reset also collects what no reachability rule could — a shared block the library holds but no page places, an element a spec created by driving the CMS, a placement whose page a crashed run took with it.

The consequence is a constraint on the database you point the suite at: **content you authored yourself in a purged class is deleted too.** `task seed-fixture` seeds this same dev DB, so treat its page tree, grid elements and shared block library as disposable. Files are deliberately outside the scope — the image an `attach_image` post-action uploads survives a reset rather than the reset emptying the whole asset store.

The `confirm=1` query parameter is required so an accidental curl or browser visit cannot wipe the dev database.

### `POST /dev/e2e-fixtures/load-all`

Resets once, then loads **every** configured fixture additively. Useful for seeding a full dev database in one request; the Playwright suite itself loads fixtures one at a time.

### Gate

Both routes run only when `Director::isDev()` is `true`. They are not available in `test`, `live`, or any other environment. Defense in depth: `canInit()` blocks route registration, and `init()` `httpError(404)`s even if the route is somehow reached.

## Registering a fixture

Fixtures are registered in `_config/dev.yml` under `WeDevelop\E2e\Fixtures\FixtureLoader.fixtures`.

### Simple fixture (path only)

```yaml
WeDevelop\E2e\Fixtures\FixtureLoader:
  fixtures:
    empty-page: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/EmptyPage.yml'
```

The path uses `vendor/package:relative/path.yml` (standard SilverStripe `ModuleResourceLoader` syntax).

### Fixture with post-actions

```yaml
WeDevelop\E2e\Fixtures\FixtureLoader:
  fixtures:
    drag-and-drop:
      path: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/DragAndDrop.yml'
      post_actions:
        - action: publish_recursive
          class: Page
          identifier: e2e_page
```

Most reorder and visibility specs follow this pattern — load the YAML, then publish the top-level page so the fixture exists on both draft and live.

### The `docs-page` fixture

One registered fixture, `docs-page` (`tests/E2E/Fixture/DocsPage.yml`), is referenced by no spec. It backs `npm run docs:screenshots`, which regenerates the images in `docs/images/` used by the README and the [grid editor guide](../usage/grid-editor.md). Leave it registered, and re-run that command if you change the page it produces.

## YAML schema

Fixtures use SilverStripe's standard `YamlFixture` syntax. Example (`tests/E2E/Fixture/ElementTree.yml`):

```yaml
# Top-down ordering. Parents written before children so =>ClassName.id
# references resolve.

Page:
  e2e_page:
    Title: 'E2E Grid Test Page'
    URLSegment: 'e2e-grid-test'

WeDevelop\Grid\Model\Section:
  section1:
    Title: 'Main Section'
    ShowTitle: 1
    Sort: 1
    Zone: main
    Parent: =>Page.e2e_page

WeDevelop\Grid\Model\Row:
  row1:
    Title: 'First Row'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Section.section1

WeDevelop\Grid\Model\Column:
  col1:
    Title: 'Left Column'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Row.row1
    GridSettings: '{"default":{"width":8,"offset":0,"visible":true},"overrides":{"xs":{"width":12,"offset":0,"visible":true}}}'

WeDevelop\Grid\Model\ContentElement:
  leaf1:
    Title: 'Text Block'
    Sort: 1
    Parent: =>WeDevelop\Grid\Model\Column.col1
```

### Ordering: top-down

Fixtures are written **top-down** (page → section → row → column → leaf). The grid declares `config_overrides` on the module's `FixtureLoader` (in `_config/dev.yml`) forcing `auto_scaffold = false` on `Section` and `Row`; the module applies each static via `FixtureBlueprint` `beforeCreate` callbacks for the duration of the fixture write, so writing parents first cannot produce duplicate auto-scaffolded children. This is the opposite of how production code behaves — in the live CMS, writing a Section triggers auto-scaffolding of a Row + Column. Fixtures opt out so the YAML stays explicit and readable.

### Required fields per element type

| Class | Required fields |
|-------|----------------|
| `Page` (or any `SiteTree` subclass) | `Title`, `URLSegment` (`e2e-` prefix by convention) |
| `Section` | `Title`, `Sort`, `Zone`, `Parent` (→ page) |
| `Row` | `Title`, `Sort`, `Parent` (→ section) |
| `Column` | `Title`, `Sort`, `Parent` (→ row), optional `GridSettings` (JSON string) |
| Content element | `Title`, `Sort`, `Parent` (→ column) |

The `Parent` field resolves to the `ParentID` column. YamlFixture also sets `ParentClass` automatically from the referenced class name.

### The `e2e-` URLSegment prefix

Cleanup no longer keys off the prefix — `purge_classes` decides what a reset deletes (see [`POST /reset`](#post-deve2e-fixturesresetconfirm1)). Keep using it anyway: it is how a page a fixture created reads as fixture data at a glance in the CMS, and specs navigate by these segments.

What you **must** do when a fixture introduces a new page type is keep it inside the purge scope. The match is polymorphic, so any `Page` subclass is already covered; a type descending straight from `SiteTree` is not, and needs its own `purge_classes` entry in `_config/dev.yml` — otherwise `reset()` silently leaves those pages behind. The `testPurgeClassesCoversEveryFixturePageType` guard test fails loudly if you forget.

### GridSettings inline

`Column.GridSettings` accepts the serialized JSON form — the same value `DBGridSettings` writes to the DB. Keep the JSON minimal: the `overrides` map must contain only viewports that deviate from `default`. An empty overrides map should be `{"default": {...}, "overrides": {}}` (or omit the field entirely).

## Post-actions

Post-actions apply versioned/state manipulations that YAML alone cannot express. They run after the YAML write, in `DRAFT` reading mode, inside `Versioned::withVersionedMode`. Four actions are supported:

### `publish_recursive`

```yaml
- action: publish_recursive
  class: Page
  identifier: e2e_page
```

Calls `$record->publishRecursive()` so the record and its owned/cascade-owned children appear on live. The most common post-action: almost every fixture publishes the top-level page.

### `unpublish`

```yaml
- action: unpublish
  class: 'WeDevelop\Grid\Model\Section'
  identifier: section2
```

Calls `$record->doUnpublish()` — useful for specs that need "modified after publish" or "draft-only" status flags.

### `modify`

```yaml
- action: modify
  class: 'WeDevelop\Grid\Model\ContentElement'
  identifier: modified_leaf
  fields:
    Title: 'Modified Text Block (draft)'
```

Calls `$record->setField($key, $value)` for each field, then `$record->write()` once after the loop. Combined with a preceding `publish_recursive`, this produces a "modified after publish" state that shows up as a yellow status pill in the CMS. Non-versioned — runs without requiring the record to have `Versioned`.

### `attach_image`

```yaml
- action: attach_image
  class: 'WeDevelop\Grid\Model\ContentElement'
  identifier: media_element
  fields:
    relation: MediaImage
    source: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/assets/test-image.png'
```

Creates a SilverStripe `Image` from a local file, writes + publishes it, and attaches it to the record via a `has_one` relation (`MediaImage` → `MediaImageID` foreign key). Use for media-block fixtures that need a real image file on disk.

### Execution order

Post-actions run in the order they appear in YAML. A common pattern is `attach_image` first (so the image exists) then `publish_recursive` (so it flows to live).

## URL helpers in Playwright

The `FixtureResult` returned from `POST /load` contains `pageId` and `fixtureMap`. The `tests/E2E/helpers/fixtures.ts` module exposes shared wrappers that hide the HTTP plumbing: `loadFixture(request, name)` (loads only), `loadAndNavigate(page, name)` (loads, opens the page editor, and waits for the grid editor to finish loading), and `resetFixtures(request)`. Typical spec header:

```ts
import { test } from '@playwright/test';
import { loadAndNavigate } from '../helpers/fixtures';

test.beforeEach(async ({ page }) => {
  const fixture = await loadAndNavigate(page, 'drag-and-drop');
});
```

## Multi-zone fixtures

Pages with multiple zones use `App\MultiZonePage` instead of `Page`. Like `Page`, this is a page type of the Docker test harness (`.docker/app/src/MultiZonePage.php`), not of the module — it adds two `GridEditorField` instances (`main` + `sidebar`) in its CMS fields. Fixtures reference it directly:

```yaml
App\MultiZonePage:
  e2e_multi_zone_page:
    Title: 'Multi Zone Page'
    URLSegment: 'e2e-multi-zone'
```

Sections then pick their zone via the `Zone` field (`main` or `sidebar`). See `tests/E2E/Fixture/MultiZone.yml` for a worked example.

## Fluent / multi-locale fixtures

Not currently supported in the shared fixture loader — the Fluent E2E suite has its own setup path. If you need a locale-scoped fixture, add `FluentState`-aware blueprints inline in the spec.

## Writing a new fixture: checklist

- [ ] New YAML file under `tests/E2E/Fixture/<Name>.yml`
- [ ] Top-down ordering (page → section → row → column → leaf)
- [ ] Page `URLSegment` starts with `e2e-` (convention) and its class is under `purge_classes`
- [ ] Every element has `Sort` and `Parent` set
- [ ] Register the fixture in `_config/dev.yml` under `FixtureLoader.fixtures`
- [ ] Add a `publish_recursive` post-action on the page if the spec needs live content
- [ ] Verify locally: `curl -k -X POST -d "fixture=<name>" "https://localhost:<WEB_PORT>/dev/e2e-fixtures/load"`
- [ ] Reference from the spec via the shared `loadAndNavigate()` / `loadFixture()` helper

## Troubleshooting

**"Fixture did not create any SiteTree records"** — the YAML is valid but contains no `SiteTree` subclass rows. Every fixture needs at least one page so the spec has something to open. Add a `Page:` entry.

**"Record not found for post-action"** — the `identifier` doesn't match any entry in the YAML. Check spelling; identifiers are case-sensitive and scoped per class.

**"Duplicate Row appeared after loading my fixture"** — auto-scaffolding leaked past the suppression. This should never happen with the standard loader; if you're writing a custom fixture loader, ensure you register `FixtureBlueprint` callbacks before calling `YamlFixture::writeInto()`.

**"My fixture page is invisible in the CMS"** — you're looking at live and the fixture has no `publish_recursive` post-action. Fixtures write to draft by default.

## See also

- [Backend Architecture — CMS Integration](../architecture/backend.md#cms-integration) — `GridPageExtension` setup
- `vendor/wedevelopnl/silverstripe-e2e/src/Fixtures/FixtureLoader.php` — the loader implementation
- `vendor/wedevelopnl/silverstripe-e2e/src/Fixtures/FixturePostAction.php` — full post-action reference
- `_config/dev.yml` — the grid's `config_overrides` block that suppresses Section/Row auto-scaffolding during fixture writes
- The `e2e-test-reference` skill (if available) — spec authoring helpers, test selectors
