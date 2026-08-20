# Contributing to SilverStripe Grid

Thank you for your interest in contributing. This document is the single reference for running the module locally, making changes, and getting them through review.

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md). By participating you are expected to uphold it. Report unacceptable behaviour to development@wedevelop.nl.

## Prerequisites

- PHP 8.3+
- Node >= 26 (see `.nvmrc`)
- Composer
- Docker Desktop (or compatible) — the PHP test suites and the dev site both run in containers
- [Task](https://taskfile.dev) — `brew install go-task/tap/go-task`, or see the [installation options](https://taskfile.dev/installation). Run `task --list` for every available target.

## Quick start

```bash
composer install
task up                  # Provisions Docker services, prints the CMS URL when ready
npm install
npm run build            # Vite production build
```

`task up` provisions a full SilverStripe 6 dev environment (FrankenPHP 8.3 with Caddy + MySQL 8). It auto-generates `.docker/.env` with deterministic ports hashed from the directory name, builds the images, runs `dev/build`, and prints the CMS URL once the app is healthy.

> First boot takes 1–2 minutes while Composer installs vendors inside the container and `dev/build` runs. Wait for `task up` to print the URL.

Run `composer install` on the host too, not only in the container: the Playwright suite and `npm run typecheck` import the fixture client from the host's `vendor/wedevelopnl/silverstripe-e2e`, and the container's vendor volume is not visible to the host.

## Local environment

- **CMS:** the URL `task up` prints — typically `https://localhost:80xx`; the exact port is `WEB_PORT` in `.docker/.env`
- **Admin:** `<that URL>/admin`, login `admin` / `admin`
- **Database:** MySQL 8 on `127.0.0.1:<DB_PORT>` (also in `.docker/.env`); database `silverstripe`, user `silverstripe`, password `silverstripe`
- **Grid adapter:** `SS_GRID_ADAPTER` is seeded into `.docker/.env` by `.docker/env.sh`. Change it there (or in your shell) to develop against another CSS framework.
- **Multi-language profile:** `task ensure-up-fluent` boots a second app container with Fluent installed, against database `silverstripe_fluent`

### Day-to-day

| Command | Description |
|---------|-------------|
| `npm run dev` | Vite watch mode — rebuilds the client bundle on change |
| `task dev-build` | Run `dev/build flush=1` inside the container |
| `task flush` | Clear the SilverStripe cache |
| `task up` | Start (or resume) services |
| `task down` | Stop services (keeps the DB volume) |
| `task destroy` | Stop services and drop volumes (full reset — wipes the DB) |
| `task seed-fixture` | Load an E2E fixture into the dev database for manual exploration (`FIXTURE=<name>`, default `complex-page`) |

## Testing

| Command | Description |
|---------|-------------|
| `task test` | All tests (PHP unit + integration + functional + JS) |
| `task test-unit` | PHP unit tests only (no database/framework) |
| `task test-integration` | PHP integration tests (full SilverStripe env) |
| `task test-functional` | PHP functional/HTTP controller tests |
| `task test-fluent` | Integration + functional + fluent tests in the Fluent environment |
| `npm run test` | JavaScript tests (Vitest) |
| `npm run test:watch` | Vitest in watch mode |
| `task test-e2e` | Playwright E2E tests (auto-starts Docker if needed) |
| `task test-e2e-ui` | Playwright E2E tests with the interactive UI |

See the [E2E fixture protocol](docs/testing/e2e-fixtures.md) for the YAML fixture system the Playwright specs use.

### Coverage and mutation testing

| Command | Description |
|---------|-------------|
| `task coverage` | Merged PHP coverage (HTML + Clover, written to `coverage/`) |
| `task coverage-unit` / `-integration` / `-functional` | PHP coverage for one suite |
| `task coverage-js` | JavaScript coverage (Vitest) |
| `task coverage-check` | Fail if combined PHP coverage is below 90% |
| `task mutate` | PHP mutation testing (Infection) |
| `task mutate-js` | JS mutation testing (Stryker) |

## Quality

| Command | Description |
|---------|-------------|
| `task qa` | **Full QA suite, run this before pushing.** PHPStan (plus a PHP 8.5 pass), Rector dry-run, PHP coverage, Biome lint, format check, typecheck, Vitest, and the Vite build — in parallel |
| `task qa-js` | JS-only QA (Biome, format check, typecheck, Vitest, Vite build) |
| `task analyse` | PHPStan static analysis (level max + Silverstan, 100% type coverage) |
| `task rector-dry` / `task rector` | Preview / apply Rector refactorings |
| `npm run lint` | Biome (JS/TS) + Stylelint (CSS) |
| `npm run format` | Biome format --write (JS/TS + CSS) |
| `npm run typecheck` | TypeScript type checking |
| `npm run i18n:check` | Dry-run string collection + locale parity check |

CI runs the same suite, plus `npm run i18n:check` and a check that the committed build output is up to date.

## Coding standards

**PHP**

- PSR-4 autoloading: `WeDevelop\Grid\` maps to `src/`
- 4-space indentation
- PHPStan level max must pass with no errors (`task analyse`), with 100% type coverage — prefer precise PHPDoc types
- Use the Result pattern for service-layer validation, not exceptions for expected failures
- Follow SilverStripe dependency injection conventions

**TypeScript / React**

- 2-space indentation
- Valibot-first types: define the schema in `client/src/js/types/`, infer the TS type with `v.InferOutput`
- Use the `@` path alias for imports from `client/src/js/`
- Follow the existing component patterns in `client/src/js/components/`

**CSS**

- Plain modern CSS in `client/src/styles/` — no Sass. Native nesting is used for
  pseudo/state selectors only; add new files to the `@import` list in `bundle.css`
- Flat kebab-case class names (`ssgrid-block-part`). BEM `__`/`--` separators are
  rejected by Stylelint's `selector-class-pattern`; variants and state live on
  `data-`/`aria-` attributes, and shared treatments are utility classes
  parameterised via `--ssgrid-*` custom properties
- 2-space indentation, must pass Stylelint

**General**

- LF line endings, UTF-8, trailing newline. See `.editorconfig` for the full rules.

## Documentation

- Keep the public API and the [architecture docs](docs/architecture/) in sync with the code.
- `CLAUDE.md` and `AGENTS.md` are generated from `.apm/instructions/` by `apm compile` — edit the sources, never the generated files. They are agent instructions, not the developer reference; user-facing documentation belongs in `docs/`.
- Screenshots in `docs/images/` are generated from the `docs-page` E2E fixture. After changing the editor UI, regenerate them with `npm run docs:screenshots` (requires a running dev environment) and commit the result. They are captured against the Bootstrap preset, so set `SS_GRID_ADAPTER=bootstrap` in `.docker/.env` first — the capture run refuses to write under any other adapter.

## Submitting changes

### Reporting bugs

- Search [existing issues](../../issues) first
- Include steps to reproduce, expected behaviour, and actual behaviour
- Mention your PHP, Node, and SilverStripe versions

### Suggesting features

- Open an issue describing the feature and its use case
- Explain why existing functionality does not cover your needs, and be open to alternative approaches

### Pull requests

1. Fork the repository and branch from `6` — the active development branch, **not** `main`
2. Make your changes following the standards above
3. Add or update tests. E2E-visible changes should come with a Playwright spec.
4. Run `task qa` locally; CI runs the same suite
5. Write a clear PR description explaining what changed and why

For major changes, open an issue first to discuss the approach.

**Frontend changes must ship their rebuilt bundles.** Run `npm run build` and commit the resulting `client/dist` and `client/lang` output alongside your source changes. CI checks both paths twice: `git diff --exit-code -- client/dist client/lang` catches modified tracked files, and a follow-up `git status --porcelain --untracked-files=all` catches new ones. `git diff` never sees untracked paths, so brand-new build output (a new chunk, a `.d.ts`, the compiled lang JS for a new locale) is caught only by the second check — verify with `git status` locally, not `git diff` alone.

### Branch strategy

- **`6`** — active development branch (SilverStripe 6). All PRs target this.
- **`main`** — legacy SilverStripe 5 version, reference only.

### Git blame

Bulk-reformat commits are listed in `.git-blame-ignore-revs` so `git blame` surfaces the real author of a line. GitHub honours this automatically; to enable it locally, run once:

```bash
git config blame.ignoreRevsFile .git-blame-ignore-revs
```

## Dev fixture endpoint

In the `dev` environment the `wedevelopnl/silverstripe-e2e` module exposes endpoints that load the same YAML fixtures the E2E suite uses, so you can explore a populated CMS by hand:

| Method | URL | Purpose |
|--------|-----|---------|
| `POST` | `/dev/e2e-fixtures/load` | Load a registered fixture (name in the POST field `fixture`, e.g. `-d fixture=complex-page`) |
| `POST` | `/dev/e2e-fixtures/reset?confirm=1` | Remove all fixture-created pages |

Both are gated by `Director::isDev()` and refuse to run outside the dev environment; reset additionally requires `?confirm=1` so a stray request cannot wipe fixture pages. `task seed-fixture` is a wrapper around the load endpoint. See the [E2E fixture protocol](docs/testing/e2e-fixtures.md) for the full protocol.

## Troubleshooting

**Port conflict after renaming or copying the worktree** — `rm .docker/.env && task up` regenerates a fresh port set.

**Stale bundle in the CMS after a rebuild** — run `task flush`, or append `?flush=1` to any CMS URL.

**Container logs** — `docker compose -f .docker/compose.yml logs -f app`

**Bumping `wedevelopnl/silverstripe-e2e`** — the version is pinned in three places: `composer.json` (host vendor) and `.docker/app/composer.json` + `.docker/app/composer.fluent.json` (container vendor). Update all three together, then rebuild with `task build`.

## License

By contributing you agree that your contributions are licensed under the [BSD-3-Clause License](LICENSE).
