# E2E Fixture Protocol

Playwright specs load their test data through a dev-only HTTP endpoint that writes named YAML fixtures into the database. This guide covers the fixture YAML schema, the post-action system, the controller's HTTP contract, and the conventions every fixture must follow.

## Architecture

```
Playwright spec
  │  await loadFixture('element-tree')
  │
  ▼
POST /dev/grid-fixtures/load  (FixtureController, dev-only)
  ├── canInit() + init() guard on Director::isDev()
  ├── Looks up name in FixtureLoader::$fixtures config
  └── Delegates to FixtureLoader::load()
       ├── reset()                  — archives all e2e-* pages
       ├── Register scaffold-suppressing FixtureBlueprints
       ├── YamlFixture::writeInto() — writes the YAML into the factory
       ├── applyPostActions()       — publish / modify / attach images
       └── Return FixtureResult     — { fixtureName, pageId, pageUrl, fixtureMap }
```

Every fixture run is fully idempotent: `load()` calls `reset()` first so re-running the same (or a different) fixture never stacks residue from a previous run.

## HTTP contract

### `POST /dev/grid-fixtures/load`

Loads the named fixture. The fixture name is read from the **POST body** field `fixture` (`$request->postVar('fixture')`) — a query-string `?fixture=` is ignored and the request fails with `400`. From the shell:

```bash
curl -X POST -d "fixture=<name>" http://localhost:<WEB_PORT>/dev/grid-fixtures/load
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

### `POST /dev/grid-fixtures/reset?confirm=1`

Archives every page whose `URLSegment` starts with `e2e-` **and** whose `ClassName` is one of the fixture page types (`FixtureLoader::FIXTURE_PAGE_CLASSES`). Requiring both prevents collateral archiving of a hand-authored page that merely shares the `e2e-` prefix on a shared dev DB. The `confirm=1` query parameter is required so an accidental curl or browser visit cannot wipe the dev database.

### Gate

Both routes run only when `Director::isDev()` is `true`. They are not available in `test`, `live`, or any other environment. Defense in depth: `canInit()` blocks route registration, and `init()` `httpError(404)`s even if the route is somehow reached.

## Registering a fixture

Fixtures are registered in `_config/dev.yml` under `WeDevelop\Grid\Dev\FixtureLoader.fixtures`.

### Simple fixture (path only)

```yaml
WeDevelop\Grid\Dev\FixtureLoader:
  fixtures:
    empty-page: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/EmptyPage.yml'
```

The path uses `vendor/package:relative/path.yml` (standard SilverStripe `ModuleResourceLoader` syntax).

### Fixture with post-actions

```yaml
WeDevelop\Grid\Dev\FixtureLoader:
  fixtures:
    drag-and-drop:
      path: 'wedevelopnl/silverstripe-grid:tests/E2E/Fixture/DragAndDrop.yml'
      post_actions:
        - action: publish_recursive
          class: Page
          identifier: e2e_page
```

Most reorder and visibility specs follow this pattern — load the YAML, then publish the top-level page so the fixture exists on both draft and live.

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

Fixtures are written **top-down** (page → section → row → column → leaf). `FixtureLoader::registerScaffoldSuppression()` sets `auto_scaffold = false` on `Section` and `Row` via `FixtureBlueprint` `beforeCreate` callbacks, so writing parents first cannot produce duplicate auto-scaffolded children. This is the opposite of how production code behaves — in the live CMS, writing a Section triggers auto-scaffolding of a Row + Column. Fixtures opt out so the YAML stays explicit and readable.

### Required fields per element type

| Class | Required fields |
|-------|----------------|
| `Page` (or any `SiteTree` subclass) | `Title`, `URLSegment` (must start with `e2e-`) |
| `Section` | `Title`, `Sort`, `Zone`, `Parent` (→ page) |
| `Row` | `Title`, `Sort`, `Parent` (→ section) |
| `Column` | `Title`, `Sort`, `Parent` (→ row), optional `GridSettings` (JSON string) |
| Content element | `Title`, `Sort`, `Parent` (→ column) |

The `Parent` field resolves to the `ParentID` column. YamlFixture also sets `ParentClass` automatically from the referenced class name.

### The `e2e-` URLSegment prefix

Every page created by a fixture **must** use a `URLSegment` that starts with `e2e-`. `FixtureLoader::reset()` and `POST /reset` archive matching pages via `doArchive()` (which cascades through `cascade_deletes` and removes from Draft + Live). Without the prefix your fixture pages will leak across test runs.

Cleanup matches on `URLSegment:StartsWith => 'e2e-'` **and** `ClassName` ∈ `FixtureLoader::FIXTURE_PAGE_CLASSES`, and the `ClassName` filter is non-polymorphic (exact match). So if you add a fixture that creates a **new** `SiteTree`/`Page` subclass, you **must** also add that class to `FIXTURE_PAGE_CLASSES` — otherwise `reset()` silently leaves those pages behind. The `testFixturePageClassesCoversEveryFixturePageType` guard test fails loudly if you forget.

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

Pages with multiple zones use `WeDevelop\Grid\Dev\MultiZonePage` instead of `Page`. This class adds two `GridEditorField` instances (`main` + `sidebar`) in its CMS fields. Fixtures reference it directly:

```yaml
WeDevelop\Grid\Dev\MultiZonePage:
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
- [ ] Page `URLSegment` starts with `e2e-`
- [ ] Every element has `Sort` and `Parent` set
- [ ] Register the fixture in `_config/dev.yml` under `FixtureLoader.fixtures`
- [ ] Add a `publish_recursive` post-action on the page if the spec needs live content
- [ ] Verify locally: `curl -X POST -d "fixture=<name>" "http://localhost:<WEB_PORT>/dev/grid-fixtures/load"`
- [ ] Reference from the spec via the shared `loadAndNavigate()` / `loadFixture()` helper

## Troubleshooting

**"Fixture did not create any SiteTree records"** — the YAML is valid but contains no `SiteTree` subclass rows. Every fixture needs at least one page so the spec has something to open. Add a `Page:` entry.

**"Record not found for post-action"** — the `identifier` doesn't match any entry in the YAML. Check spelling; identifiers are case-sensitive and scoped per class.

**"Duplicate Row appeared after loading my fixture"** — auto-scaffolding leaked past the suppression. This should never happen with the standard loader; if you're writing a custom fixture loader, ensure you register `FixtureBlueprint` callbacks before calling `YamlFixture::writeInto()`.

**"My fixture page is invisible in the CMS"** — you're looking at live and the fixture has no `publish_recursive` post-action. Fixtures write to draft by default.

## See also

- [Backend Architecture — CMS Integration](../architecture/backend.md#cms-integration) — `GridPageExtension` setup
- `src/Dev/FixtureLoader.php` — the loader implementation
- `src/Dev/FixturePostAction.php` — full post-action reference
- The `e2e-test-reference` skill (if available) — spec authoring helpers, test selectors
