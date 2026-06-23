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
src/Dev/              # Fixture loading for E2E tests (controller, loader, post-actions, result)
src/Factory/          # Factories (GridAdapterFactory)
src/Model/            # Element models (GridElement, Section, Row, Column, ContentElement) + ContainerElementTrait
src/Extensions/       # SilverStripe extensions (GridPageExtension, BlockMediaExtension, FluentGridPageExtension)
src/Forms/            # Form field implementations (GridEditorField, GridAwareVersionFormFactory)
src/Migration/        # SS5→SS6 migration (DTOs, strategies, tasks, services)
src/Reports/          # CMS reports (GridElementReport)
src/Value/            # Value objects, DTOs, and request objects (NodeRef, NodeType, ElementStatus, GridNode, Result, ...)
src/Service/          # Domain services (GridTreeBuilder, GridNodeMapper, ElementPlacementService, GridElementService, GridSettingsService, GridSettingsResolver, TitleGenerator, RequestBodyParser, ColumnClassResolver, GridAwareDeleteLocalisationPolicy)
src/Validation/       # Write-time + reorder-time validation (HierarchyValidationExtension, HierarchyValidationService, HierarchyValidatorInterface, ReorderValidator, GridSettingsFieldValidator)
src/Exception/        # Domain exceptions (GridDomainException, InvalidGridValueException)
src/ORM/FieldType/    # Custom DB field types (DBGridSettings composite field)
src/Repository/       # Repository interfaces + ORM implementations (GridElementRepositoryInterface, OrmGridElementRepository)
tests/Unit/           # PHPUnit unit tests (no DB/framework)
tests/Integration/    # PHPUnit integration tests (full SS env)
tests/E2E/            # Playwright E2E tests
tests/E2E/Fixture/    # YAML fixtures for E2E test data
tests/E2E/specs/      # E2E test specs
tests/E2E/helpers/    # Shared E2E test utilities
client/src/           # Frontend source (React/TS/SCSS)
client/src/api/       # API client layers (client, endpoints, config, errors)
client/src/boot/      # Component registration
client/src/bridge/    # SilverStripe CMS integration (entwine, Injector)
client/src/bundles/   # Entry points
client/src/components/ # React components
client/src/hooks/     # React hooks, query keys, TanStack Query, mutations
client/src/styles/    # SCSS styles
client/src/types/     # Zod schemas, TypeScript types
client/src/utils/     # Frontend utility functions
client/src/testing/   # Test infrastructure (factories, helpers, mocks)
client/src/i18n/      # Internationalization utilities
client/dist/          # Vite build output (exposed, created by build)
scripts/              # Build scripts (i18n collection, parity checks)
phpstan/stubs/        # PHPStan stubs (e.g. AdminController.stub)
.docker/              # Docker dev env: Caddy + PHP + MySQL 8
docs/architecture/    # Architecture documents (backend, drag-and-drop)
```

- PSR-4 namespace: `WeDevelop\Grid\` → `src/`
- Frontend: React 19, TypeScript 6, Vite 8, SCSS
- Key frontend libs: dnd-kit (drag & drop), TanStack Query (data fetching), Zod (validation)
- Testing: Vitest + React Testing Library (jsdom), PHPUnit 11, Playwright (E2E)
- Node: >=24 (pinned to 24.13 in `.nvmrc`)
- Docker dev env: Caddy + PHP + MySQL 8 (see `.docker/`)

## Key Files

- `vite.config.ts` — Build config + Vitest test config, `@` alias → `client/src`
- `tsconfig.json` — TypeScript config
- `playwright.config.ts` — Playwright E2E test config (base URL from `.docker/.env` or `E2E_BASE_URL`)
- `stryker.config.mjs` — Stryker JS mutation testing config
- `Taskfile.yml` — Docker-based PHP test/coverage commands (run via [Task](https://taskfile.dev))
- `.docker/compose.yml` — Docker service definitions
- `.docker/env.sh` — Generates `.docker/.env` with deterministic ports
- `.docker/app/infection.json5` — Infection mutation testing config
- `.docker/app/phpstan.neon.dist` — PHPStan config (level max + Silverstan + 100% type coverage)
- `.docker/app/rector.php` — Rector config (curated rule set, see "Rector" section below)

## PHP Testing

- PHPUnit 11 — runs inside Docker via `task test`
- PHPUnit config: `.docker/app/phpunit.xml.dist` (defines `unit`, `integration`, `functional`, and `fluent` testsuites, selected via `--testsuite` flag)
- Test namespace: `WeDevelop\Grid\Tests\` → `tests/` (Unit/ + Integration/)

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
  - `ChangeOrIfContinueToMultiContinueRector` — splitting `if (!a \|\| !b) continue` into two `if` blocks is often less readable
  - `FlipTypeControlToUseExclusiveTypeRector` — `$x !== null` on a typed `?Foo` property is more honest about intent than `$x instanceof Foo`
  - `PostIncDecToPreIncDecRector` — pure micro-style, codebase consistently uses post-increment
