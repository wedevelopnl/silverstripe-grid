---
description: Project architecture, directory structure, key files, testing, and static analysis
applyTo: "**/*"
---

# Architecture

```
_config/              # YAML config (DI bindings, element hierarchy, grid adapter)
templates/            # SilverStripe .ss templates (element holders + form fields)
src/                  # PHP source (PSR-4: WeDevelop\Grid\)
src/Adapter/          # Grid framework adapters: GridAdapter base class + framework presets (Bootstrap, Tailwind, Bulma)
src/Contract/         # Interfaces (GridAdapterInterface, ContentLayoutAdapterInterface, ContainerInterface, ReorderValidatorInterface)
src/Controllers/      # API controllers (GridController)
src/Factory/          # Factories (GridAdapterFactory)
src/Model/            # Element models (GridElement, Section, Row, Column, ContentElement) + ContainerElementTrait
src/Extensions/       # SilverStripe extensions (GridPageExtension, BlockMediaExtension, FluentGridPageExtension)
src/Forms/            # Form field implementations (GridEditorField, GridAwareVersionFormFactory)
src/Migration/        # SS5→SS6 migration (DTOs, strategies, tasks, services); src/Migration/Service/ holds GridMigrationService (orchestration), DraftHierarchyWriter, LivePublisher, PageGridFlagWriter, LegacyPageDiscovery, LegacyElementReader, LegacyDataReader (facade)
src/Reports/          # CMS reports (GridElementReport)
src/Value/            # Value objects, DTOs, and request objects (NodeRef, NodeType, ElementStatus, GridNode, Result, MigrationIdMap, ...)
src/Service/          # Domain services (GridTreeService, GridNodeMapper, ElementPlacementService, GridElementService, GridSettingsService, GridSettingsResolver, TitleGenerator, RequestBodyParser, ColumnClassResolver, GridAwareDeleteLocalisationPolicy)
src/Validation/       # Write-time + reorder-time validation (HierarchyValidationExtension, HierarchyValidationService, HierarchyValidatorInterface, ReorderValidator, GridSettingsFieldValidator)
src/Exception/        # Domain exceptions (GridDomainException, InvalidGridValueException)
src/ORM/FieldType/    # Custom DB field types (DBGridSettings composite field)
src/Repository/       # Repository interfaces + ORM implementations (GridElementRepositoryInterface, OrmGridElementRepository)
tests/Unit/           # PHPUnit unit tests (no DB/framework)
tests/Integration/    # PHPUnit integration tests (full SS env)
tests/Functional/     # PHPUnit functional tests (HTTP/controller)
tests/E2E/            # Playwright E2E tests
tests/E2E/Fixture/    # YAML fixtures for E2E test data
tests/E2E/specs/      # E2E test specs
tests/E2E/helpers/    # Shared E2E test utilities
tests/E2E/screenshots/ # Doc screenshot captures (npm run docs:screenshots); NOT run by test-e2e
client/src/           # Frontend source (React/TS/SCSS): js/ + styles/
client/src/js/        # React/TS source
client/src/js/api/    # API client layers (client, endpoints, config, errors)
client/src/js/boot/   # Component registration
client/src/js/bridge/ # SilverStripe CMS integration (entwine, Injector)
client/src/js/bundles/ # Entry points
client/src/js/components/ # React components
client/src/js/hooks/  # React hooks, query keys, TanStack Query, mutations
client/src/js/state/  # Cross-component state modules (activeViewport)
client/src/js/types/  # Valibot schemas, TypeScript types
client/src/js/utils/  # Frontend utility functions
client/src/js/testing/ # Test infrastructure (factories, helpers, mocks)
client/src/js/i18n/   # Internationalization utilities
client/src/styles/    # SCSS styles (_tokens + _fonts + _typography + _a11y are the shared layer)
client/fonts/         # Self-hosted Poppins woff2 subsets + OFL licence; copied to client/dist/fonts/ at build
client/dist/          # Vite build output (exposed, created by build)
scripts/              # Build scripts (i18n collection, parity checks)
phpstan/stubs/        # PHPStan stubs (e.g. AdminController.stub)
.docker/              # Docker dev env: Caddy + PHP + MySQL 8
.docker/app/src/      # Harness-owned page types (App\MultiZonePage — the E2E multi-zone page); COPYed to /app/src at image build
docs/                 # User documentation; docs/README.md is the index
docs/usage/           # Usage guides (grid-editor, custom-elements, templates, i18n)
docs/architecture/    # Architecture documents (backend, drag-and-drop, grid-adapter)
docs/images/          # Generated doc screenshots — regenerate, never hand-edit
```

- PSR-4 namespace: `WeDevelop\Grid\` → `src/`
- Frontend: React 18, TypeScript 6, Vite 8, SCSS
- Key frontend libs: dnd-kit (drag & drop), TanStack Query (data fetching), Valibot (validation)
- Testing: Vitest + React Testing Library (jsdom), PHPUnit 12, Playwright (E2E)
- Node: >=26 (pinned to 26.4.0 in `.nvmrc`)
- Docker dev env: Caddy + PHP + MySQL 8 (see `.docker/`)

## Key Files

- `vite.config.ts` — Build config + Vitest test config, `@` alias → `client/src/js`
- `tsconfig.json` — TypeScript config
- `playwright.config.ts` — Playwright E2E test config (base URL from `.docker/.env` or `E2E_BASE_URL`)
- `playwright.docs.config.ts` — Doc screenshot config. Separate file on purpose: `task test-e2e` runs `npx playwright test` with no project filter, so a screenshot project in the main config would rewrite `docs/images/` on every E2E run.
- `stryker.config.mjs` — Stryker JS mutation testing config
- `Taskfile.yml` — Docker-based PHP test/coverage commands (run via [Task](https://taskfile.dev))
- `.docker/compose.yml` — Docker service definitions
- `.docker/env.sh` — Generates `.docker/.env` with deterministic ports
- `.docker/app/infection.json5` — Infection mutation testing config
- `.docker/app/phpstan.neon.dist` — PHPStan config (level max + Silverstan + 100% type coverage)
- `.docker/app/phpstan-php85.neon.dist` — PHPStan config with the analysis target pinned to PHP 8.5
- `.docker/app/rector.php` — Rector config (curated rule set, see "Rector" section below)

## PHP Testing

- PHPUnit 12 — runs inside Docker via `task test`
- PHPUnit config: `.docker/app/phpunit.xml.dist` (defines `unit`, `integration`, `functional`, and `fluent` testsuites, selected via `--testsuite` flag). COPYed into the image at build, **not** volume-mounted — after editing, rebuild with `task build` or push it with `docker compose -f .docker/compose.yml cp .docker/app/phpunit.xml.dist app:/app/phpunit.xml.dist` (and the same to `app-fluent`)
- `failOnRisky` + `failOnWarning` are on: a test that asserts nothing fails the run rather than being reported as risky and exiting 0
- Test namespace: `WeDevelop\Grid\Tests\` → `tests/` (mirrors the subdirectory: `Tests\Functional\Controllers` → `tests/Functional/Controllers/`)

## Static Analysis

- PHPStan level max with Silverstan (SilverStripe-aware rules)
- 100% type coverage enforced: return, param, property, constant, declare
- Runs inside Docker via `task analyse`

## Rector

- Config: `.docker/app/rector.php` (COPYed into the image at build, **not** volume-mounted — after editing, rebuild the image with `task build` or push the file with `docker compose -f .docker/compose.yml cp .docker/app/rector.php app:/app/rector.php`)
- Scope: `src/` only (tests are excluded)
- Enforced as a QA gate: the internal `qa-rector` task runs `rector process --dry-run` inside `task qa` and fails the build if any rule would change a file
- Workflow: contributors run `task rector` locally to apply fixes, commit the result, then push
- Curated rule set — `codingStyle` prepared set is **not** enabled. The following rules are explicitly skipped via `withSkip()`:
  - `FlipTypeControlToUseExclusiveTypeRector` — `$x !== null` on a typed `?Foo` property is more honest about intent than `$x instanceof Foo`
  - `PostIncDecToPreIncDecRector` — pure micro-style, codebase consistently uses post-increment
