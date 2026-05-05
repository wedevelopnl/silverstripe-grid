---
description: Available npm and Makefile commands for development, testing, and QA
applyTo: "**/*"
---

# Commands

## npm

| Command | Description |
|---------|-------------|
| `npm run build` | Vite production build |
| `npm run dev` | Vite watch mode for development |
| `npm run test` | Run Vitest tests |
| `npm run lint` | Biome lint (JS/TS) + Stylelint (SCSS) |
| `npm run lint:js` | Biome lint only (no fix) |
| `npm run lint:js:fix` | Biome lint with auto-fix |
| `npm run lint:css` | Stylelint only (no fix) |
| `npm run lint:css:fix` | Stylelint with auto-fix |
| `npm run format` | Biome format --write (JS/TS) |
| `npm run format:check` | Biome format check (no write) |
| `npm run typecheck` | TypeScript type checking |
| `npm run test:watch` | Vitest in watch mode |
| `npm run coverage` | Vitest with coverage report |
| `npm run mutate` | JS mutation testing (Stryker) |
| `npm run i18n:collect` | Collect JS i18n strings |
| `npm run i18n:check` | Dry-run collect + parity check |
| `npm run test:e2e` | Run Playwright E2E tests |
| `npm run test:e2e:ui` | Playwright with interactive UI |
| `npm run test:e2e:debug` | Playwright in debug mode |
| `npm run qa` | Full QA: lint + format:check + typecheck + test + i18n:check + vite build |

## PHP (via Makefile — requires Docker)

| Command | Description |
|---------|-------------|
| `make up` | Start Docker services (build if needed) |
| `make down` | Stop Docker services |
| `make destroy` | Stop services and remove volumes |
| `make build` | Build Docker images without starting |
| `make test` | Run all tests (PHP unit + integration + functional + JS) |
| `make test-unit` | Run PHP unit tests (no database/framework) |
| `make test-integration` | Run PHP integration tests (full SilverStripe env) |
| `make test-functional` | Run PHP functional tests (HTTP/controller tests) |
| `make test-fluent` | Run integration + functional + fluent tests in Fluent env |
| `make test-js` | Run JavaScript tests (Vitest, no Docker needed) |
| `make coverage` | Merged PHP coverage report (HTML + Clover) |
| `make coverage-unit` | PHP unit test coverage only |
| `make coverage-integration` | PHP integration test coverage only |
| `make coverage-functional` | PHP functional test coverage only |
| `make coverage-js` | JavaScript test coverage (Vitest) |
| `make mutate` | PHP mutation testing (Infection) |
| `make mutate-js` | JS mutation testing (Stryker) |
| `make analyse` | Run PHPStan static analysis |
| `make rector` | Run Rector refactoring (applies changes) |
| `make rector-dry` | Run Rector in dry-run mode (preview only) |
| `make test-e2e` | Run Playwright E2E tests (requires Docker) |
| `make test-e2e-ui` | Playwright E2E with interactive UI |
| `make flush` | Clear SilverStripe cache |
| `make dev-build` | Run dev/build to rebuild database and manifest |
| `make qa` | Full QA suite (PHPStan + Rector + PHP coverage + JS QA, parallel) |
| `make qa-js` | JavaScript QA (Biome + Stylelint + typecheck + Vitest + vite build) |
