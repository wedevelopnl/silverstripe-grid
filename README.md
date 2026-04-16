# SilverStripe Grid

## Introduction

A grid-based content block system for SilverStripe CMS, enabling structured Section → Row → Column layouts with configurable CSS framework adapters (Bootstrap, Tailwind, Bulma).

## Requirements

* PHP ^8.3
* silverstripe/framework ^6.0
* silverstripe/cms ^6.0
* silverstripe/admin ^3.0
* silverstripe/versioned ^3.0
* silverstripe/vendor-plugin ^3.0
* Node >=24 (for frontend build)

> **Optional**: `silverstripe/reports` enables the Grid Elements report in CMS Reports.

> **Conflict**: This module conflicts with `dnadesign/silverstripe-elemental` and replaces its functionality.

## Installation

```
composer require wedevelopnl/silverstripe-grid
```

## Usage

The module ships `GridPageExtension` which adds a grid editor to page types. Apply it to the page types that need grid editing in your project's YAML config:

```yaml
# app/_config/grid.yml
Page:
  extensions:
    Grid: WeDevelop\Grid\Extensions\GridPageExtension
```

Each page with the extension gets a **"Use grid on this page"** checkbox that toggles between the grid editor and the standard Content HTMLEditorField.

### Page templates

Page templates for types with the extension must handle both modes. Use `$UseGrid` to render grid sections or fall back to `$Content`:

```silverstripe
<% if $UseGrid %>
    <% loop $Sections %>$Me<% end_loop %>
<% else %>
    $Content
<% end_if %>
```

### Configuring the default

The grid is enabled by default on new pages. Override this per page type in YAML:

```yaml
App\Pages\ArticlePage:
  use_grid_by_default: true    # default — grid editor on new pages

App\Pages\JobPage:
  use_grid_by_default: false   # content editor on new pages
```

CMS users can still toggle the checkbox per page regardless of the default.

## Development

### Prerequisites

* Docker Desktop (or compatible)
* Node >=24 (see `.nvmrc`)
* Composer

### Quick start

```bash
composer install
make up                  # Provisions Docker services, prints CMS URL when ready
npm install
npm run build            # Vite production build
```

`make up` provisions a full SilverStripe 6 dev environment (FrankenPHP 8.3 + MySQL 8 + Caddy). It auto-generates `.docker/.env` with deterministic ports hashed from the directory name, builds the Docker images, runs `dev/build`, and prints the CMS URL once the app is healthy.

> First boot takes ~1–2 minutes while Composer installs vendors inside the container and `dev/build` runs. Wait for `make up` to print the URL.

### What you get

* **CMS:** the URL printed by `make up` (typically `https://localhost:80xx` — the exact port lives in `.docker/.env` as `WEB_PORT`)
* **Admin:** `<that URL>/admin` — login `admin` / `admin`
* **Database:** MySQL 8 exposed on `127.0.0.1:<DB_PORT>` (also in `.docker/.env`); database `silverstripe`, user `silverstripe`, password `silverstripe`
* **Optional multi-language profile:** `make ensure-up-fluent` boots a second app container with Fluent installed against database `silverstripe_fluent`

### Day-to-day

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite watch mode — rebuilds the client bundle on change |
| `make dev-build` | Run `dev/build flush=1` inside the container |
| `make flush` | Clear SilverStripe cache |
| `make up` | Start (or resume) services |
| `make down` | Stop services (keeps DB volume) |
| `make destroy` | Stop services and drop volumes (full reset — wipes DB) |

> **Port conflict after renaming or copying the worktree?** Run `rm .docker/.env && make up` to regenerate a fresh port set.
>
> **View container logs:** `docker compose -f .docker/compose.yml logs -f app`

### Dev fixture endpoint

When running in the `dev` environment, the module exposes endpoints to load the same YAML fixtures used by E2E tests into the CMS for manual exploration:

| Method | URL | Purpose |
|--------|-----|---------|
| `POST` | `/dev/grid-fixtures/load?fixture=<Name>` | Load `tests/E2E/Fixture/<Name>.yml` |
| `POST` | `/dev/grid-fixtures/reset` | Remove all fixture-created pages |

See `src/Dev/FixtureController.php`. The endpoints are gated by `Director::isDev()` and refuse to run outside the dev environment.

### Testing

| Command | Description |
|---------|-------------|
| `make test` | Run all tests (PHP unit + integration + functional + JS) |
| `make test-unit` | PHP unit tests only (no database/framework) |
| `make test-integration` | PHP integration tests (full SilverStripe env) |
| `make test-functional` | PHP functional/HTTP controller tests |
| `make test-fluent` | Run integration + fluent tests in the Fluent environment |
| `npm run test` | JavaScript tests (Vitest) |
| `npm run test:watch` | Vitest in watch mode |
| `make test-e2e` | Playwright E2E tests (auto-starts Docker if needed) |
| `make test-e2e-ui` | Playwright E2E tests with the interactive UI |

### Coverage & mutation testing

| Command | Description |
|---------|-------------|
| `make coverage` | Merged PHP coverage (HTML + Clover, written to `coverage/`) |
| `make coverage-unit` | PHP unit test coverage only |
| `make coverage-integration` | PHP integration test coverage only |
| `make coverage-functional` | PHP functional test coverage only |
| `make coverage-js` | JavaScript test coverage (Vitest) |
| `make coverage-check` | Fail if combined PHP coverage < 90% |
| `make mutate` | PHP mutation testing (Infection) |
| `make mutate-js` | JavaScript mutation testing (Stryker) |

### Quality

| Command | Description |
|---------|-------------|
| `make analyse` | PHPStan static analysis (level max + Silverstan, 100% type coverage) |
| `make rector-dry` | Preview Rector refactorings |
| `make rector` | Apply Rector refactorings |
| `npm run lint` | Biome (JS/TS) + Stylelint (SCSS) |
| `npm run format` | Biome format --write (JS/TS) |
| `npm run typecheck` | TypeScript type checking |
| `make qa` | Full QA suite — PHPStan + coverage + lint + typecheck + JS tests (parallel) |
| `make qa-js` | JS-only QA — lint + typecheck + Vitest (parallel) |

### Architecture

See the [architecture documentation](docs/architecture/) for detailed design documents:

- [Backend Architecture](docs/architecture/backend.md) — data model, API layer, service design, validation, grid adapters
- [Drag and Drop](docs/architecture/drag-and-drop.md) — frontend dnd-kit integration and backend reorder pipeline

### Migrating from Elemental / ElementalGrid

Projects upgrading from WeDevelop ElementalGrid (SS5) or plain `dnadesign/silverstripe-elemental` can use the built-in migration tasks. See [Migrating from Elemental / ElementalGrid](docs/migration.md) for the step-by-step guide, strategy diagrams, and extension hooks.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

See [License](LICENSE)

## Maintainers

* [WeDevelop](https://www.wedevelop.nl/) <development@wedevelop.nl>

## Development and contribution

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.
