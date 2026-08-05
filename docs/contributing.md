# Contributing

Everything you need to run the module locally, make changes, and run the full QA suite.

## Prerequisites

- Docker Desktop (or compatible)
- Node >= 26 (see `.nvmrc`)
- Composer
- [Task](https://taskfile.dev) — `brew install go-task/tap/go-task` (see [installation options](https://taskfile.dev/installation)). Run `task --list` to see all tasks.

## Quick start

```bash
composer install
task up                  # Provisions Docker services, prints CMS URL when ready
npm install
npm run build            # Vite production build
```

`task up` provisions a full SilverStripe 6 dev environment (FrankenPHP 8.3 + MySQL 8 + Caddy). It auto-generates `.docker/.env` with deterministic ports hashed from the directory name, builds the Docker images, runs `dev/build`, and prints the CMS URL once the app is healthy.

> First boot takes ~1–2 minutes while Composer installs vendors inside the container and `dev/build` runs. Wait for `task up` to print the URL.

## Local environment

- **CMS:** the URL printed by `task up` (typically `https://localhost:80xx` — the exact port lives in `.docker/.env` as `WEB_PORT`)
- **Admin:** `<that URL>/admin` — login `admin` / `admin`
- **Database:** MySQL 8 exposed on `127.0.0.1:<DB_PORT>` (also in `.docker/.env`); database `silverstripe`, user `silverstripe`, password `silverstripe`
- **Optional multi-language profile:** `task ensure-up-fluent` boots a second app container with Fluent installed against database `silverstripe_fluent`

## Day-to-day

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite watch mode — rebuilds the client bundle on change |
| `task dev-build` | Run `dev/build flush=1` inside the container |
| `task flush` | Clear SilverStripe cache |
| `task up` | Start (or resume) services |
| `task down` | Stop services (keeps DB volume) |
| `task destroy` | Stop services and drop volumes (full reset — wipes DB) |

## Testing

| Command | Description |
|---------|-------------|
| `task test` | Run all tests (PHP unit + integration + functional + JS) |
| `task test-unit` | PHP unit tests only (no database/framework) |
| `task test-integration` | PHP integration tests (full SilverStripe env) |
| `task test-functional` | PHP functional/HTTP controller tests |
| `task test-fluent` | Run integration + functional + fluent tests in the Fluent environment |
| `npm run test` | JavaScript tests (Vitest) |
| `npm run test:watch` | Vitest in watch mode |
| `task test-e2e` | Playwright E2E tests (auto-starts Docker if needed) |
| `task test-e2e-ui` | Playwright E2E tests with the interactive UI |

See [E2E fixture protocol](testing/e2e-fixtures.md) for the YAML fixture system Playwright specs use.

The Playwright suite (and `npm run typecheck`) imports the fixture client from `vendor/wedevelopnl/silverstripe-e2e`, so run `composer install` on the host once before running E2E tests — the Docker container's vendor volume is not visible to the host. When bumping the `wedevelopnl/silverstripe-e2e` version, update `composer.json` and the exact pins in `.docker/app/composer.json` + `.docker/app/composer.fluent.json` together, then rebuild the image (`task build`).

## Coverage & mutation testing

| Command | Description |
|---------|-------------|
| `task coverage` | Merged PHP coverage (HTML + Clover, written to `coverage/`) |
| `task coverage-unit` | PHP unit test coverage only |
| `task coverage-integration` | PHP integration test coverage only |
| `task coverage-functional` | PHP functional test coverage only |
| `task coverage-js` | JavaScript test coverage (Vitest) |
| `task coverage-check` | Fail if combined PHP coverage < 90% |
| `task mutate` | PHP mutation testing (Infection) |
| `task mutate-js` | JavaScript mutation testing (Stryker) |

## Quality

| Command | Description |
|---------|-------------|
| `task analyse` | PHPStan static analysis (level max + Silverstan, 100% type coverage) |
| `task rector-dry` | Preview Rector refactorings |
| `task rector` | Apply Rector refactorings |
| `npm run lint` | Biome (JS/TS) + Stylelint (SCSS) |
| `npm run format` | Biome format --write (JS/TS) |
| `npm run typecheck` | TypeScript type checking |
| `task qa` | Full QA suite (parallel) — PHPStan (+ PHP 8.5 pass), Rector dry-run (build-failing gate), PHP coverage, Biome lint, format check, typecheck, Vitest, and the Vite build |
| `task qa-js` | JS-only QA (parallel) — Biome lint, format check, typecheck, Vitest, and the Vite build |

## Dev fixture endpoint

When running in the `dev` environment, the `wedevelopnl/silverstripe-e2e` module (dev dependency) exposes endpoints to load the same YAML fixtures used by E2E tests into the CMS for manual exploration:

| Method | URL | Purpose |
|--------|-----|---------|
| `POST` | `/dev/e2e-fixtures/load` | Load a registered fixture (name in the POST body field `fixture`, e.g. `-d fixture=<Name>`) |
| `POST` | `/dev/e2e-fixtures/reset?confirm=1` | Remove all fixture-created pages |

The endpoints are gated by `Director::isDev()` and refuse to run outside the dev environment. The reset endpoint requires `?confirm=1` so an accidental curl or browser visit cannot wipe fixture-loaded pages. See [E2E fixture protocol](testing/e2e-fixtures.md) for the full protocol (YAML schema, post-actions, registering new fixtures).

## Troubleshooting

**Port conflict after renaming or copying the worktree?** Run `rm .docker/.env && task up` to regenerate a fresh port set.

**Container logs:** `docker compose -f .docker/compose.yml logs -f app`

**Stale bundle in CMS after a rebuild:** run `task flush` (or append `?flush=1` to any CMS URL) to clear the SilverStripe cache.

## Pull requests

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

When contributing code:

- Run `task qa` locally before pushing — CI runs the same suite.
- **Frontend changes must ship their rebuilt bundles.** Run `npm run build` and commit the resulting `client/dist` and `client/lang` output with your source changes: CI rebuilds and then runs `git diff --exit-code -- client/dist client/lang`, failing on both modified and untracked files there. This catches brand-new build output (a new chunk, a `.d.ts`, a compiled lang file) as well as changes to existing bundles.
- PHPStan runs at level max with 100% type coverage. Prefer precise PHPDoc types (see `.apm/instructions/php-conventions.instructions.md`).
- E2E changes should come with a Playwright spec covering the new behavior. See the [E2E fixture protocol](testing/e2e-fixtures.md).
- Keep the public API and the architecture docs (`docs/architecture/`) in sync. `CLAUDE.md` and `AGENTS.md` are generated from `.apm/instructions/` via `apm compile` — edit the sources, never the generated files.
