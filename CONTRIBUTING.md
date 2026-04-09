# Contributing to SilverStripe Grid

Thank you for your interest in contributing to SilverStripe Grid! This document provides guidelines and instructions for contributing.

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code. Please report unacceptable behavior to development@wedevelop.nl.

## Getting Started

### Prerequisites

- PHP 8.3+
- Node.js 24+ (see `.nvmrc`)
- Docker (for PHP tests and the development environment)

### Setting Up the Development Environment

1. Clone the repository
2. Install PHP dependencies: `composer install`
3. Install Node dependencies: `npm install`
4. Start the Docker environment: `make up`
5. Build the frontend: `npm run build` (or `npm run dev` for watch mode)

The Docker environment provides Caddy, PHP, and MySQL 8. Default admin credentials are `admin`/`admin`.

## How to Contribute

### Reporting Bugs

- Search [existing issues](../../issues) before opening a new one
- Use the bug report issue template if available
- Include steps to reproduce, expected behavior, and actual behavior
- Mention your PHP version, Node version, and SilverStripe version

### Suggesting Features

- Open an issue describing the feature and its use case
- Explain why existing functionality doesn't cover your needs
- Be open to discussion about alternative approaches

### Submitting Pull Requests

1. Fork the repository and create your branch from `6` (the active development branch, **not** `main`)
2. Make your changes following the coding standards below
3. Add or update tests as appropriate
4. Ensure all checks pass (see [Quality Assurance](#quality-assurance))
5. Write a clear PR description explaining what changed and why

## Coding Standards

### PHP

- Follow PSR-4 autoloading (`WeDevelop\Grid\` maps to `src/`)
- 4-space indentation
- PHPStan level max must pass with no errors (`make analyse`)
- Use the Result pattern for service-layer validation (not exceptions)
- Use SilverStripe dependency injection conventions

### TypeScript / React

- 2-space indentation
- Zod-first type definitions (schemas in `client/src/types/`, infer TS types)
- Use the `@` path alias for imports from `client/src/`
- Follow existing component patterns in `client/src/components/`

### CSS / SCSS

- 2-space indentation
- Must pass Stylelint checks

### General

- LF line endings, UTF-8, trailing newline on all files
- See `.editorconfig` for full formatting rules

## Quality Assurance

Before submitting a PR, ensure the relevant checks pass:

### Full QA (recommended)

```bash
# PHP: static analysis + unit + integration tests
make qa

# JavaScript: lint + typecheck + unit tests
npm run qa
```

### Individual Checks

| Command | Scope |
| ------- | ----- |
| `make analyse` | PHPStan static analysis |
| `make test-unit` | PHP unit tests |
| `make test-integration` | PHP integration tests (requires Docker) |
| `npm run lint` | Biome (JS/TS) + Stylelint (SCSS) |
| `npm run format` | Biome format --write (JS/TS) |
| `npm run typecheck` | TypeScript type checking |
| `npm run test` | Vitest unit tests |

### E2E Tests

E2E tests require running Docker services and are not part of the standard QA suite:

```bash
make test-e2e
```

## Project Structure

See the `CLAUDE.md` file for a detailed overview of the architecture, directory layout, and key conventions.

## Branch Strategy

- **`6`** — Active development branch (SilverStripe 6 rewrite)
- **`main`** — Legacy SilverStripe 5 version (reference only)

All PRs should target the `6` branch.

## License

By contributing, you agree that your contributions will be licensed under the [BSD-3-Clause License](LICENSE).
