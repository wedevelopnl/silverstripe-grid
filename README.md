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

## Development

### Prerequisites

* Docker (for PHP tests and dev environment)
* Node >=24 (see `.nvmrc`)
* Composer

### Setup

```bash
composer install
make up                  # Start Docker services (auto-generates .docker/.env)
npm install
npm run build            # Vite production build
```

### Testing

| Command | Description |
|---------|-------------|
| `make test` | Run all tests (PHP unit + integration + JS) |
| `make test-unit` | PHP unit tests only (no database/framework) |
| `make test-integration` | PHP integration tests (full SilverStripe env) |
| `npm run test` | Run JavaScript tests (Vitest) |
| `make test-e2e` | Run Playwright E2E tests (requires running Docker services) |

### Quality

| Command | Description |
|---------|-------------|
| `make analyse` | PHPStan static analysis (level max) |
| `npm run lint` | oxlint + Stylelint |
| `npm run typecheck` | TypeScript type checking |
| `make qa` | Full QA suite (PHPStan + PHP tests + JS QA) |

### Architecture

See the [architecture documentation](docs/architecture/) for detailed design documents:

- [Backend Architecture](docs/architecture/backend.md) — data model, API layer, service design, validation, grid adapters
- [Drag and Drop](docs/architecture/drag-and-drop.md) — frontend dnd-kit integration and backend reorder pipeline

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

See [License](LICENSE)

## Maintainers

* [WeDevelop](https://www.wedevelop.nl/) <development@wedevelop.nl>

## Development and contribution

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.
