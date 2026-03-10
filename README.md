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
.docker/env.sh          # Generate .docker/.env with auto-assigned ports
make up                  # Start Docker services
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

### Issue Tracking with Beads

This project uses [Beads](https://github.com/steveyegge/beads) for issue tracking. Beads is a git-backed issue tracker that lives in the repository, designed for AI-assisted development workflows.

#### Quick Start

Install beads (one-time setup):

```bash
# Pick one:
npm install -g @beads/bd
brew install beads
go install github.com/steveyegge/beads/cmd/bd@latest
```

For other installation methods, see the [official documentation](https://github.com/steveyegge/beads).

Common commands:

```bash
bd ready                              # Find issues ready to work on
bd show <id>                          # View issue details
bd update <id> --status=in_progress   # Claim an issue
bd close <id>                         # Mark issue as done
bd sync                               # Sync issues with git remote
```

Issues are stored in `.beads/` and committed alongside code. No external services or web UIs needed.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for release history.

## License

See [License](LICENSE)

## Maintainers

* [WeDevelop](https://www.wedevelop.nl/) <development@wedevelop.nl>

## Development and contribution

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.
