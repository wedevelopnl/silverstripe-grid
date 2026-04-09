# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [6.0.0-alpha.4] - 2026-04-09

### Added

- **SS5 → SS6 Elemental migration tool** — `MigrateRowsToSectionsTask` and `MigrateRowsToSingleSectionTask` BuildTasks migrate legacy `wedevelopnl/silverstripe-elemental-grid` (and plain `dnadesign/silverstripe-elemental`) content into the new Section→Row→Column hierarchy. Two row-mapping strategies (`RowPerSection`, `AllRowsInSection`), `FieldMapper` for grid settings / media fields / class names (with invalid-value clamping and a cross-framework lookup table), `LegacyDataReader` for raw SQL reads against legacy tables with schema discovery, `ElementGrouper` for row-boundary splitting, and `GridMigrationService` orchestrating the full flow. Preserves live-specific content, requires explicit confirmation before destructive runs, and ships with a 7-scenario acceptance test suite covering cross-framework (Bootstrap → Tailwind) and custom-element migrations. See [`docs/migration.md`](docs/migration.md)
- **Extension hook for FieldMapper lookup tables** — projects can customise the class name lookup used during migration via a standard SilverStripe extension point, enabling bespoke element class mapping without subclassing
- **Fluent localisation support** — opt-in multi-locale grid editing via `FluentGridPageExtension` and `GridAwareDeleteLocalisationPolicy`. Elements are isolated per locale, with auto-scaffolding inheriting the active locale, and support for Fluent's copy-to-locale and clear-from-locale actions. Adds `tractorcow/silverstripe-fluent` as a `suggest` dependency, a dedicated `fluent` Docker service, and a `make test-fluent` target. See [`docs/fluent.md`](docs/fluent.md)
- **Bulk reset of viewport overrides** — a reset action on the viewport switcher clears all per-viewport overrides for the current column in one call. Backed by `ResetGridSettingsOverridesRequest`, the `useResetOverridesAction` hook, and a dedicated API endpoint
- **Automatic CMS preview refresh** — the CMS preview pane now refreshes automatically after any grid mutation, eliminating stale previews after add/edit/reorder/archive
- **`GridElementService` and `GridSettingsService`** — business logic extracted from `GridController` into dedicated, testable services
- **`GridNodeMapper` service** — element-to-`GridNode` DTO conversion extracted from `GridTreeBuilder`
- **`GridSettingsSerializer` service** — dedicated serialisation layer for `DBGridSettings` read/write

### Changed

- **`AbstractGridAdapter` replaced with a single config-driven `GridAdapter`** — the abstract adapter hierarchy is gone. `GridAdapter` is now a concrete class that reads everything (CSS format strings, class maps, scalar values) from `Configurable` statics, and `BootstrapAdapter` / `TailwindAdapter` / `BulmaAdapter` are zero-method subclasses declaring only `private static` property overrides. Configuration is resolved on demand, eliminating instance property duplication. The `ContentLayoutAdapter` class and `ContentLayoutClassMap` value object are folded into `GridAdapter` directly
- **`GridController` slimmed down** — the controller is now a thin HTTP adapter; validation, orchestration, and persistence live in `GridElementService`, `GridSettingsService`, `RequestBodyParser`, and `TitleGenerator`
- **Ownership validation errors map to HTTP 400** — previously surfaced as 500, now correctly classified as client errors
- **`OrmGridElementRepository.findByParents` query paths unified** — single code path for all parent lookups, removing duplicated query logic
- **Template rendering unified on `GridElement` base class** — per-subclass rendering duplication eliminated

### Fixed

- CMS preview pane not refreshing after grid mutations (stale preview state)

### Dependencies

- `@tanstack/react-query` 5.95 → 5.96
- `@playwright/test` 1.58 → 1.59
- Vite 8.0.2 → 8.0.7
- Vitest 4.1.1 → 4.1.3
- `@vitest/coverage-v8` 4.1.1 → 4.1.2
- jsdom 29.0.1 → 29.0.2
- oxlint 1.57 → 1.59
- sass-embedded 1.98 → 1.99
- Stylelint 17.5 → 17.6
- `@types/node` 25.2 → 25.5
- Added `@testing-library/jest-dom` ^6.9 (dev)
- Added `tractorcow/silverstripe-fluent` as suggested (not required)

### Developer Experience

- **Frontend test suite redesigned** — tests co-located with source files (`client/src/utils/*.test.ts` next to `*.ts`) instead of a mirrored `client/src/tests/` tree, with proper isolation and shared infrastructure consolidated
- **Integration & functional test expansion** — new `FunctionalTest` coverage for `GridController` API endpoints, `FixtureController`, `GridElementReport`, `BlockMediaExtension`, and the `Dev/` fixture infrastructure; new `SapphireTest` integration suite alongside a pure unit suite with targeted refactorings for testability
- **Data-provider consolidation** — `GridTreeBuilderTest`, `RequestBodyParserTest`, `GridSettingsSerializerTest`, `GridSettingsResolverTest`, `FieldMapperTest`, and the migration strategy tests converted from one-method-per-case to `#[DataProvider]` tables
- Expanded README Development section into a full quick start

## [6.0.0-alpha.3] - 2026-03-25

### Added

- **Duplicate-to feature** — deep-copy any grid element to a different container, zone, or page via a multi-step dialog. Includes `apiDuplicateTo` endpoint with hierarchy validation, `DuplicateToRequest` value object, `DuplicateToDialog` React component, `acceptableContainers`/`zones`/`pages` GET endpoints, and E2E test coverage
- **`DBGridSettings` composite field** — `GridSettings` is now stored as a `DBComposite` (`DBGridSettings`) with dedicated `GridSettingsDefault` and `GridSettingsOverrides` database columns, replacing the single JSON Text column. Includes `GridSettingsFieldValidator` for field-level validation
- **`OverrideStrategy` enum** — configurable per-adapter override strategy (`isolated` or `cascade`) controlling how viewport overrides are resolved. Set via YAML `override_strategy` on the adapter class; defaults to `isolated`
- **`WriteResult` utility** — lightweight result wrapper for element write operations, replacing the heavier `ElementPersistenceService`

### Changed

- **GridSettings model replaced with intent-based default+overrides** — the sparse mobile-first cascade is replaced by an explicit `{ default, overrides }` model where the default holds base layout settings and overrides store only per-viewport deviations. `resolveViewportSettings` resolves effective settings by checking for an override then falling back to the default
- **`ElementPersistenceService` removed** — CRUD operations now use `WriteResult` directly, reducing indirection
- **`ReorderExecutor` and `ReorderService` merged into `ReorderService`** — the separate `ReorderExecutor` class and `ReorderExecutorInterface` are removed. `ReorderService` now handles both validation and execution directly, reducing indirection in the reorder pipeline
- **Zod removed from frontend runtime** — all Zod schemas replaced with plain TypeScript interfaces and type guards, eliminating the `zod` runtime dependency entirely
- **`ElementActions` extracted** — shared action menu component extracted from individual block components, reducing duplication
- **Grid hierarchy rules hardcoded in `ContainerType` enum** — `allowed_elements` / `disallowed_elements` / `can_be_root` moved from YAML config into `ContainerType`, making the hierarchy statically analysable

### Fixed

- Grid editor not spanning full width in CMS edit form
- Owning page not touched after grid element mutations (stale cache in CMS page list)
- Vite 8 IIFE bundle emitting `require()` for externalized React
- 346 false-positive lint warnings from dist output files
- SCSS module declaration and `.d.ts` output paths broken under TypeScript 6
- TS2882 warnings for SCSS imports during build

### Performance

- Upgraded Vite from 7.x to 8.x

### Dependencies

- TypeScript 5.9 → 6.0
- Vite 7.3 → 8.0
- `@vitejs/plugin-react` 5.2 → 6.0
- `@tanstack/react-query` 5.90 → 5.95
- jsdom 26.1 → 29.0
- oxlint 1.55 → 1.57
- Stylelint 17.4 → 17.5
- Vitest 4.1.0 → 4.1.1

### Developer Experience

- Dependabot configured for npm, Composer, and Docker dependencies
- `package-lock.json` committed for reproducible builds

## [6.0.0-alpha.2] - 2026-03-13

### Added

- **BlockMediaExtension** — opt-in extension for content elements that pairs media (image/video) with text in a responsive two-column layout. Supports aspect ratios, vertical alignment, media positioning, and per-framework CSS output via the content layout adapter system
- **Content layout adapter system** — `ContentLayoutAdapterInterface` + data-driven `ContentLayoutClassMap` for framework-specific content layout CSS (aspect ratios, ordering, padding, alignment). Each grid adapter provides its class map via `getContentLayoutClassMap()`
- **ColumnWidthPickerField** — visual column-width picker with illustrated layout previews for the BlockMediaExtension admin UI
- **TypeScript type declarations** shipped in `client/dist/types/` for downstream consumers
- `CONTRIBUTING.md` and `CODE_OF_CONDUCT.md`

### Changed

- **`GridAdapterConfiguration` trait replaced with `AbstractGridAdapter` base class** — custom adapters must now extend `AbstractGridAdapter` instead of using the trait. The base class handles viewport filtering, column count resolution, default viewport resolution, container max width, and visibility map generation. Adapter implementations are significantly simpler as a result
- **Per-framework content layout adapters consolidated** — `BootstrapContentLayoutAdapter`, `TailwindContentLayoutAdapter`, and `BulmaContentLayoutAdapter` replaced by a single `ContentLayoutAdapter` driven by `ContentLayoutClassMap` factory methods on each grid adapter
- **Custom JS field toggling replaced with `display-logic`** — BlockMediaExtension form field visibility now uses the `display-logic` SilverStripe module instead of custom entwine JS (`composer.json` gains `silverstripe/display-logic` dependency)
- `GridController` validation and request deserialization extracted into `RequestBodyParser` and `TitleGenerator` services
- `useDragAndDrop` hook decomposed into focused sub-hooks (`usePendingTree`, `resolveDropPlacement`)

### Fixed

- Cross-container drop direction incorrect during pending moves — pointer position was compared against viewport-relative `getBoundingClientRect()` instead of dnd-kit's coordinate system
- Cross-container drop at last position blocked when source container would be depleted
- `GridSettings` sparse cascade not preserving explicit user overrides that match the cascaded default
- PHPStan stub conflicts with Silverstan resolved
- ESM compatibility: replaced `__dirname` with `import.meta.url`

### Performance

- Migrated from `zod` to `zod/v4-mini` reducing validation library bundle size
- Externalized `react-dom` to avoid bundling it twice

### Developer Experience

- `make up` auto-generates `.docker/.env` if missing — no manual step needed
- `make qa` and `make qa-js` now run in parallel for faster feedback
- Testbed URL printed after `make up` completes
- PHP code coverage threshold raised from 69% to 90%
- Rector with `silverstripe-rector` added for automated refactoring

## [6.0.0-alpha.1] - 2026-03-10

Ground-up rewrite for SilverStripe 6. This is a new package (`wedevelopnl/silverstripe-grid`) that replaces the SS5 `wedevelopnl/silverstripe-elemental-grid` module with an independent architecture — no dependency on `dnadesign/silverstripe-elemental`.

### Added

#### Grid System
- Three-level container hierarchy: Section > Row > Column with strict validation
- `GridElement` abstract base class with polymorphic parent relationships (`ParentID` + `ParentClass`)
- `ContainerInterface` and `ContainerElementTrait` for shared container behavior
- `ContentElement` base class for leaf elements within columns
- Zone-scoped sections (e.g., `main`, `sidebar`) with independent sort order per zone
- Auto-scaffolding: creating a Section automatically produces the full Section > Row > Column tree
- Hierarchy validation at write time (`HierarchyValidationExtension`) and reorder time (`ReorderValidator`)
- Configurable element allowlists/blocklists per container type via YAML

#### Grid Adapters
- `GridAdapterInterface` contract for CSS framework abstraction (12 methods)
- `GridAdapterConfiguration` trait for YAML-configurable viewport filtering, column counts, and defaults
- Built-in adapters: Bootstrap 5, Tailwind CSS, and Bulma
- Sparse `GridSettings` with mobile-first cascade — only viewport overrides are stored
- Per-viewport column width, offset, and visibility controls

#### CMS Integration
- React-based grid editor with self-contained layout (no CSS framework required in CMS)
- Drag-and-drop reordering powered by dnd-kit with 3-tier collision detection
- Cross-container drag support with optimistic pending tree updates
- Collapsible containers with localStorage persistence
- Viewport switcher for previewing responsive layouts
- Element cards with publication state indicators
- Add-child buttons on all container types
- Element actions menu with archive action
- Clickable container titles linking to edit forms
- Title tag/class configuration on all grid elements
- Grid Settings tab on Column edit forms with per-viewport controls
- `GridElementReport` for listing all grid elements in CMS Reports

#### Backend Services
- `GridTreeBuilder` — builds the element tree for a page and zone
- `ElementPersistenceService` — CRUD operations returning `Result` objects
- `ReorderService` + `ReorderExecutor` — validates and executes element reordering
- `OrmGridElementRepository` — repository abstraction over SilverStripe ORM
- `Result<T>` pattern for validation flows (replaces exceptions for expected failures)
- `GridController` REST API with endpoints for tree reading, element CRUD, and reorder

#### Frontend
- React 18 + TypeScript 5.9 + Vite 7 build pipeline
- TanStack Query for data fetching with optimistic updates and cache invalidation
- Zod schemas for runtime validation of API responses
- SilverStripe CMS bridge via entwine and Injector
- SCSS styles with CSS variable theming

#### SilverStripe Templates
- `Section.ss`, `Row.ss`, `Column.ss` templates for frontend rendering
- Adapter-driven CSS class generation for rows, columns, containers, and visibility

#### Testing
- PHPUnit 11 test suite (unit + integration) running in Docker
- PHPStan level max with Silverstan and 100% type coverage
- Infection PHP mutation testing
- Vitest + React Testing Library frontend tests
- Stryker JS mutation testing
- Playwright E2E test infrastructure with HTTP-based fixture system
- E2E specs covering layout rendering, viewport switching, drag-and-drop, content editing, grid settings, and multi-zone support

#### Developer Experience
- Docker dev environment (Caddy + PHP + MySQL 8) with deterministic port assignment
- Makefile with targets for testing, coverage, static analysis, and mutation testing
- Pre-push QA gate hook

[6.0.0-alpha.4]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.4
[6.0.0-alpha.3]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.3
[6.0.0-alpha.2]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.2
[6.0.0-alpha.1]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.1
