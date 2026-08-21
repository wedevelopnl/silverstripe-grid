# Documentation

Start with the [project README](../README.md) for what the module is and how to install it. This page is the map of everything else.

## Using the module

Read these in order if you are new to the module.

| Guide | Covers |
|-------|--------|
| [The grid editor](usage/grid-editor.md) | What CMS users see and do: hierarchy, adding content, widths and offsets, viewports, drag and drop, publishing, element actions |
| [Shared blocks](usage/shared-blocks.md) | Content maintained once and placed on many pages: creating, placing, editing in place, the independent publish lifecycle, detaching, templates, Fluent |
| [Custom content elements](usage/custom-elements.md) | Subclassing `ContentElement`, CMS fields, editor-card summaries, icons, templates |
| [Template integration](usage/templates.md) | The holder chain, zones and multi-zone pages, the per-page editor toggle, theme overrides, class-contribution hooks |
| [Internationalization](usage/i18n.md) | Translating PHP and React strings, adding a locale, the collectors and the parity check |

## Integrating with existing sites

| Guide | Covers |
|-------|--------|
| [Migrating from Elemental / ElementalGrid](migration.md) | The `migrate-grid` and `migrate-grid-with-fluent` tasks, strategies, options, extension hooks, troubleshooting |
| [Fluent (multi-locale) support](fluent.md) | Installing Fluent alongside the grid, per-locale isolation, copy/clear behaviour, assigning a locale to existing records |

## Architecture

Reference material for working on the module itself, or for extending it beyond what the usage guides cover.

| Document | Covers |
|----------|--------|
| [Backend architecture](architecture/backend.md) | Data model, polymorphic parents, grid settings, API layer, services, validation, repositories, DI |
| [Grid Adapter System](architecture/grid-adapter.md) | The config-driven adapter base class, preset reference, writing an adapter for another framework |
| [Drag and Drop](architecture/drag-and-drop.md) | Coordinate spaces, collision detection, the optimistic update pipeline, the backend reorder pipeline |

## Contributing

| Document | Covers |
|----------|--------|
| [Contributing guide](../CONTRIBUTING.md) | Dev environment, day-to-day commands, tests, coverage, QA, PR conventions |
| [E2E fixture protocol](testing/e2e-fixtures.md) | Fixture YAML schema, post-actions, the dev fixture endpoint, writing a new fixture |

## Screenshots

The images in `docs/images/` are generated, not captured by hand. They all come from the `docs-page` fixture (`tests/E2E/Fixture/DocsPage.yml`), and `npm run docs:screenshots` regenerates the whole set against a running dev environment — run it after any change to the editor UI so the guides do not drift from the product.

They are captured against the **Bootstrap** preset, because the editor guide names Bootstrap's column count and viewports in its prose. `.docker/env.sh` seeds `SS_GRID_ADAPTER=tailwind`, so set `SS_GRID_ADAPTER=bootstrap` in `.docker/.env` and restart the app container before regenerating. The capture run refuses to write anything under any other adapter.
