# Contributing

Everything you need to run the module locally, make changes, and run the full QA suite.

## Prerequisites

- Docker Desktop (or compatible)
- Node >= 24 (see `.nvmrc`)
- Composer

## Quick start

```bash
composer install
make up                  # Provisions Docker services, prints CMS URL when ready
npm install
npm run build            # Vite production build
```

`make up` provisions a full SilverStripe 6 dev environment (FrankenPHP 8.3 + MySQL 8 + Caddy). It auto-generates `.docker/.env` with deterministic ports hashed from the directory name, builds the Docker images, runs `dev/build`, and prints the CMS URL once the app is healthy.

> First boot takes ~1–2 minutes while Composer installs vendors inside the container and `dev/build` runs. Wait for `make up` to print the URL.

## Local environment

- **CMS:** the URL printed by `make up` (typically `https://localhost:80xx` — the exact port lives in `.docker/.env` as `WEB_PORT`)
- **Admin:** `<that URL>/admin` — login `admin` / `admin`
- **Database:** MySQL 8 exposed on `127.0.0.1:<DB_PORT>` (also in `.docker/.env`); database `silverstripe`, user `silverstripe`, password `silverstripe`
- **Optional multi-language profile:** `make ensure-up-fluent` boots a second app container with Fluent installed against database `silverstripe_fluent`

## Day-to-day

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite watch mode — rebuilds the client bundle on change |
| `make dev-build` | Run `dev/build flush=1` inside the container |
| `make flush` | Clear SilverStripe cache |
| `make up` | Start (or resume) services |
| `make down` | Stop services (keeps DB volume) |
| `make destroy` | Stop services and drop volumes (full reset — wipes DB) |

## Testing

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

See [E2E fixture protocol](testing/e2e-fixtures.md) for the YAML fixture system Playwright specs use.

## Coverage & mutation testing

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

## Quality

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

## Dev fixture endpoint

When running in the `dev` environment, the module exposes endpoints to load the same YAML fixtures used by E2E tests into the CMS for manual exploration:

| Method | URL | Purpose |
|--------|-----|---------|
| `POST` | `/dev/grid-fixtures/load?fixture=<Name>` | Load a registered fixture |
| `POST` | `/dev/grid-fixtures/reset?confirm=1` | Remove all fixture-created pages |

The endpoints are gated by `Director::isDev()` and refuse to run outside the dev environment. The reset endpoint requires `?confirm=1` so an accidental curl or browser visit cannot wipe fixture-loaded pages. See [E2E fixture protocol](testing/e2e-fixtures.md) for the full protocol (YAML schema, post-actions, registering new fixtures).

## Troubleshooting

**Port conflict after renaming or copying the worktree?** Run `rm .docker/.env && make up` to regenerate a fresh port set.

**Container logs:** `docker compose -f .docker/compose.yml logs -f app`

**Stale bundle in CMS after a rebuild:** run `make flush` (or append `?flush=1` to any CMS URL) to clear the SilverStripe cache.

## Pull requests

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

When contributing code:

- Run `make qa` locally before pushing — CI runs the same suite.
- PHPStan runs at level max with 100% type coverage. Prefer precise PHPDoc types (see `.apm/instructions/php-conventions.instructions.md`).
- E2E changes should come with a Playwright spec covering the new behavior. See the [E2E fixture protocol](testing/e2e-fixtures.md).
- Keep the public API and the architecture docs (`docs/architecture/`) in sync. `CLAUDE.md` and `AGENTS.md` are generated from `.apm/instructions/` via `apm compile` — edit the sources, never the generated files.
