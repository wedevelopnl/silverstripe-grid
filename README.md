# SilverStripe Grid

A grid-based content block system for SilverStripe 6 CMS — structured Section → Row → Column layouts with configurable CSS framework adapters (Bootstrap, Tailwind, Bulma).

## Requirements

- PHP ^8.3
- silverstripe/framework ^6.0, silverstripe/cms ^6.0, silverstripe/admin ^3.0, silverstripe/versioned ^3.0, silverstripe/vendor-plugin ^3.0
- unclecheese/display-logic ^4.0, wedevelopnl/silverstripe-media-field ^6.0.0-rc3
- Node >= 26 (only needed if you build the frontend yourself)

Optional:

- `silverstripe/reports` — enables the Grid Elements report in CMS Reports
- `tractorcow/silverstripe-fluent` — multi-locale support with isolated grid records per locale (see [Fluent integration](docs/fluent.md))

> **Conflict**: this module conflicts with `dnadesign/silverstripe-elemental` and replaces its functionality.

## Installation

```bash
composer require wedevelopnl/silverstripe-grid
```

Then run `dev/build?flush=1` to pick up the new database schema and configuration.

Set the required `SS_GRID_ADAPTER` environment variable to select the active CSS framework adapter — a bundled preset name (`bootstrap`, `tailwind`, or `bulma`, case-insensitive) or the fully-qualified class name of a custom adapter. If it is unset, empty, or invalid the module throws when the container boots. See [Grid Adapter System](docs/architecture/grid-adapter.md) for the full reference.

## Usage

Apply `GridPageExtension` to the page types that should have grid editing:

```yaml
# app/_config/grid.yml
Page:
  extensions:
    Grid: WeDevelop\Grid\Extensions\GridPageExtension
```

Render the grid in the page template:

```silverstripe
<% loop $Sections %>$Me<% end_loop %>
```

That's a working integration. See [Template integration](docs/usage/templates.md) for the per-page editor toggle, default-behavior configuration, theme overrides, and the holder chain.

## Documentation

### Usage guides

- [Custom content elements](docs/usage/custom-elements.md) — subclass `ContentElement`, register CMS fields, add templates
- [Template integration](docs/usage/templates.md) — `GridPageExtension` configuration, holder chain, theme overrides, extension hooks
- [Internationalization](docs/usage/i18n.md) — translating strings, adding a locale, PHP + JS collectors

### Integration guides

- [Migrating from Elemental / ElementalGrid](docs/migration.md) — `BuildTask`-based upgrade from SS5 `silverstripe-elemental` / `silverstripe-elemental-grid`
- [Fluent (multi-locale) support](docs/fluent.md) — optional integration with `tractorcow/silverstripe-fluent`

### Architecture

- [Backend architecture](docs/architecture/backend.md) — data model, API layer, service design, validation
- [Drag and Drop](docs/architecture/drag-and-drop.md) — frontend dnd-kit integration and backend reorder pipeline
- [Grid Adapter System](docs/architecture/grid-adapter.md) — building a new CSS framework adapter

### Contributing

- [Contributing guide](docs/contributing.md) — dev environment, test/coverage/QA commands, pull-request conventions
- [E2E fixture protocol](docs/testing/e2e-fixtures.md) — YAML schema, post-actions, dev fixture endpoint

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

See [LICENSE](LICENSE).

## Maintainers

[WeDevelop](https://www.wedevelop.nl/) — <development@wedevelop.nl>
