---
description: Available npm and Task (Taskfile) commands for development, testing, and QA
applyTo: "**/*"
---

# Commands

## npm

| Command | Description |
|---------|-------------|
| `npm run build` | Vite production build |
| `npm run dev` | Vite watch mode for development |
| `npm run test` | Run Vitest tests |
| `npm run lint` | Biome lint (JS/TS) + Stylelint (CSS) |
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
| `npm run check:icons` | Fail on any `font-icon-*` class the admin font does not define |
| `npm run test:e2e` | Run Playwright E2E tests |
| `npm run test:e2e:ui` | Playwright with interactive UI |
| `npm run test:e2e:debug` | Playwright in debug mode |
| `npm run docs:screenshots` | Regenerate `docs/images/` from the `docs-page` fixture (separate config; not part of `test:e2e`) |
| `npm run qa` | Full QA: lint + format:check + typecheck + test + i18n:check + check:icons + vite build |

## PHP (via Task — requires Docker)

Run with [Task](https://taskfile.dev) (`task <name>`). Install: `brew install go-task/tap/go-task` (see https://taskfile.dev/installation). List all tasks with `task --list`.

| Command | Description |
|---------|-------------|
| `task up` | Start Docker services (build if needed) |
| `task down` | Stop Docker services |
| `task destroy` | Stop services and remove volumes |
| `task build` | Build Docker images without starting |
| `task test` | Run all tests (PHP unit + integration + functional + JS) |
| `task test-unit` | Run PHP unit tests (no database/framework) |
| `task test-integration` | Run PHP integration tests (full SilverStripe env) |
| `task test-functional` | Run PHP functional tests (HTTP/controller tests) |
| `task test-fluent` | Run integration + functional + fluent tests in Fluent env |
| `task test-js` | Run JavaScript tests (Vitest, no Docker needed) |
| `task coverage` | Merged PHP coverage report (HTML + Clover) |
| `task coverage-unit` | PHP unit test coverage only |
| `task coverage-integration` | PHP integration test coverage only |
| `task coverage-functional` | PHP functional test coverage only |
| `task coverage-js` | JavaScript test coverage (Vitest) |
| `task coverage-check` | Check PHP coverage meets the 90% minimum threshold |
| `task mutate` | PHP mutation testing (Infection) |
| `task mutate-js` | JS mutation testing (Stryker) |
| `task analyse` | Run PHPStan static analysis |
| `task analyse-php85` | PHPStan with the analysis target pinned to PHP 8.5 (forward-compat pass, in addition to the 8.3-range primary) |
| `task rector` | Run Rector refactoring (applies changes) |
| `task rector-dry` | Run Rector in dry-run mode (preview only) |
| `task test-e2e` | Run Playwright E2E tests (requires Docker) |
| `task test-e2e-ui` | Playwright E2E with interactive UI |
| `task flush` | Clear SilverStripe cache |
| `task dev-build` | Run dev/build to rebuild database and manifest |
| `task seed-fixture` | Seed the dev DB with an E2E fixture (`FIXTURE=<name>`, default `complex-page`; idempotent) |
| `task qa` | Full QA suite (PHPStan + Rector + PHP coverage + JS QA, parallel) |
| `task qa-js` | JavaScript QA (Biome + Stylelint + typecheck + Vitest + vite build) |
