# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Grid editor blocks split into chrome + editable/readonly variants** — each block (`ElementCard`, `ColumnBlock`, `RowBlock`, `SectionBlock`) is now a presentational `*Chrome` plus an `Editable*`/`Readonly*` variant; editor mode is decided once at the root instead of per-node. No public API or rendered output changes for integrators.
- **i18n key renamed: the four `*.MODIFIED_LABEL` keys collapse into one `WeDevelopGrid.ModifiedIndicator.LABEL`** — `WeDevelopGrid.ColumnBlock.MODIFIED_LABEL`, `WeDevelopGrid.ElementCard.MODIFIED_LABEL`, `WeDevelopGrid.RowBlock.MODIFIED_LABEL`, and `WeDevelopGrid.SectionBlock.MODIFIED_LABEL` (shipped in `6.0.0-alpha.6`) are replaced by a single `WeDevelopGrid.ModifiedIndicator.LABEL`. The bundled `en`/`nl` text is unchanged, so default output is identical — but any project that overrode one of the four old JS i18n keys must move that override to the new key, or it will silently stop applying.
- **Build tooling migrated from `make` to [Task](https://taskfile.dev)** — the `Makefile` is replaced by `Taskfile.yml`. Run `task <name>` (e.g. `task up`, `task qa`, `task test`); the target names are unchanged. Contributors must install Task (`brew install go-task/tap/go-task`); CI installs it via `arduino/setup-task`. The QA suite now runs its checks in parallel through Task's `deps` instead of `make -j8`.

## [6.0.0-alpha.6] - 2026-06-15

### Added

- **Grid editor visual redesign** — the CMS grid editor is restyled end to end. Adds a base design-token layer, styled Section/Row/Column blocks and element cards, a styled viewport-switcher toolbar, and styled popovers, dialogs, and drag overlay. The editor gains a dedicated shell with a Grid-area header and positioned add buttons; the per-element kebab menu is replaced by a 6-icon element toolbar, and the "Add column" bar becomes "+" insert squares
- **CMS preview viewport sync** — the CMS preview pane width now follows the editor's active viewport. A viewport selector mounts into the preview toolbar (piggy-backing on the vendor toolbar) and an `activeViewport` pub-sub store keeps editor and preview in sync
- **Per-viewport `min_width`** — `viewport_definitions` carries a `min_width` per entry, exposed on the `Viewport` value object, serialised into the adapter config, and surfaced to the frontend via `ViewportConfig.minWidth`. A `forMalformedViewportDefinition` exception factory guards malformed entries
- **`ContentElement::getSummary()`** — content elements ship a default summary derived from their HTML field, surfaced on grid editor cards so integrators get a meaningful preview label without custom code. See [`docs`](docs) for the integrator example
- **Insert element before first sibling** — the API and the "+" insert squares allow creating an element at the start of a container, not just appended
- **Collapse/expand-all toggle** — the Grid-area header button toggles between collapsing and expanding all containers
- **Element history toolbar button** — wired to open the CMS version-history tab for the element
- **API responses validated with Zod at the boundary** — runtime validation of API responses is reintroduced via Zod, scoped to the API boundary only (alpha.3 had removed Zod from the frontend runtime entirely). PHP empty-array maps are coerced to objects so they parse correctly

### Changed

- **`GridPageExtension` and the per-page editor toggle are now opt-in** — the extension is no longer auto-applied to every page; projects enable the grid editor per page type explicitly
- **Adapter selection requires the `SS_GRID_ADAPTER` env var** — the active adapter is selected via `SS_GRID_ADAPTER`, which accepts a bundled preset name or the FQCN of a custom adapter; unset/empty/invalid values throw at container boot (see the Grid Adapter System docs)
- **Element mutation unified via `ElementPlacementService`** — reorder, insert, and placement now flow through a single service; `GridTreeBuilder` is split into a builder and a walker, and tree-walk helpers moved to private controller methods
- **Auto-scaffolding consolidated in `GridElement::onAfterWrite()`** — the child class to scaffold is derived from `ContainerType` rather than per-subclass logic
- **Single-target mutations migrated from bare IDs to `NodeRef`** — completing the `NodeRef` identity migration across the frontend mutation layer; the redundant `id` field is dropped from `BaseFields`
- **`ElementStatus` shipped precomputed** — the API ships a precomputed `ElementStatus` instead of raw `StatusFlags` for the client to assemble
- **`GridSettings` serialisation inlined via `JsonSerializable`** — the value objects serialise themselves at the storage boundary; the standalone serializer utility statics are removed
- **Editor blocks styled via BEM classes and a `data-react-mount` attribute** — component styling is decoupled from `data-testid` selectors, and mount detection (entwine + CMS preview bridge) switches to a dedicated `data-react-mount` attribute

### Fixed

- `can*` permission checks not honouring `extendedCan`, ignoring extension-provided permission decisions
- Multi-element writes not wrapped in a database transaction, risking partial state on failure; grid settings override strategy now resolved via DI
- Mutation lookups not pinned to the DRAFT stage, risking reads against the wrong stage
- `DBGridSettings` read-path overrides asymmetry; zero-width settings no longer dropped, and malformed writes are logged rather than silently suppressed
- Hierarchy validation messages not localised, lacking an explicit error code, and missing a column-offset guard
- Migration: silent skip on a missing shared-element record (now fails loud), inconsistent sort across stages, ungrouped live-only layout, implicit visibility, and a hardcoded `UseGrid` table name (now resolved dynamically)
- `BlockMediaExtension` media parsing, embed error handling, and `auto_scaffold` scoping hardened against malformed input
- Frontend: section list not memoised, keyboard a11y gaps, viewport schema accepting out-of-bounds values, destination tree not invalidated after a cross-container move, and the error channel left untyped
- CMS preview bridge not surviving CMS content-area swaps (save/publish Pjax) and not actually rescaling the preview
- Element actions toolbar missing its ARIA role and label
- Editor visuals: row card chrome and modified-state wiring, element card underlining its header on hover, between-column insert handle off-centre in offset gutters, "Add row / Add section" button outline, and column width/offset layout in row columns
- Cross-container drops mis-aimed with the larger redesigned blocks
- `getCMSFields` manipulations not wrapped in `beforeUpdateCMSFields`
- Dev fixture reset not scoped to fixture page classes, allowing it to touch real pages

### Performance

- Dialogs mounted only while open, with `content-visibility` applied to off-screen sections
- Tree leaves memoised and their props stabilised to cut editor re-renders
- DnD per-frame linear scans replaced with O(1) map lookups

### Dependencies

- `@tanstack/react-query` 5.99 → 5.100.14
- `zod` ^4.4.3 (re-added, scoped to the API boundary)
- `@biomejs/biome` 2.4 → 2.5.0
- `@playwright/test` 1.59.1 → 1.60.0
- `@stryker-mutator/typescript-checker` 9.5 → 9.6.1
- `@stryker-mutator/vitest-runner` 9.5 → 9.6.1
- `@vitejs/plugin-react` 6.0.1 → 6.0.2
- `@vitest/coverage-v8` 4.1.2 → 4.1.8
- `@types/node` 25.6 → 25.9.3
- TypeScript 6.0.2 → 6.0.3
- Vite 8.0.8 → 8.0.16
- `vite-plugin-dts` 4.5.4 → 5.0.2
- Vitest 4.1.4 → 4.1.8
- jsdom 29.0.2 → 29.1.1
- sass-embedded 1.99 → 1.100.0
- Stylelint 17.8 → 17.13.0
- `js-yaml` 4.1.1 → 4.2.0
- `infection/infection` ^0.32 → ^0.33
- `wedevelopnl/silverstripe-media-field` ^6.0 → ^6.0.0-rc3

### Developer Experience

- **Stricter Biome config** — Biome lint/format scope expanded from `client/src/` to the whole repo, and `vite build` added to `npm run qa` to gate compile errors and stale `dist` output
- **CI hardening** — Rector dry-run enforced as a static-analysis gate, PHPStan run once across the supported PHP range, the E2E suite run as a matrix across the Bootstrap and Tailwind adapters, and the Node major version asserted against `.nvmrc` before the QA diff check
- **Mutation testing hardened** — PHP Infection score raised from 87% to 92% with behavioural tests and centrally-documented equivalent-mutant ignores; escaped Stryker (JS) mutants killed and smelly inline suppressions removed
- **E2E discipline pass** — specs refactored to one user journey per `describe`, hardcoded waits and class-based selectors removed in favour of visible-state and role/test-id locators, and specs made adapter-agnostic
- **Vitest suite runs under React StrictMode** to surface unsafe effects
- **`type-coverage` 2.2 adopted**, dropping obsolete `paramTypeCoverage` ignores
- **Documentation overhaul** — README refocused as an entry point with a separate contributing guide; new integrator guides for custom elements, templates, and i18n; an E2E fixture protocol guide; and refreshed architecture docs for `ElementPlacementService`, the DnD `NodeRef` identity model, and grid-settings serialisation

## [6.0.0-alpha.5] - 2026-04-16

### Added

- **Full internationalisation (i18n)** — all user-facing strings in both PHP and React are now translatable via SilverStripe's `_t()` / `ss.i18n._t()` system. Ships with complete English and Dutch (`nl`) translations for PHP (`lang/en.yml`, `lang/nl.yml`) and JavaScript (`client/lang/src/en.json`, `client/lang/src/nl.json`). Includes a JS key collector script, EN/NL parity check in CI, and `ValidationError` objects now carry translation metadata
- **Version history viewer** — grid elements show a version history tab on their detail forms via `GridAwareVersionFormFactory`. The grid editor renders in readonly mode when viewing a historical version, with all interactive controls (drag handles, action menus, add-child buttons) hidden. Backed by version-aware tree loading in `GridController` and a `ReadonlyContext` propagated through the component tree
- **Per-page grid editor toggle** — pages can switch between the grid editor and the standard content editor via `GridPageExtension`, allowing mixed content strategies within a single site
- **`NodeRef` and `NodeType` identity model** — collision-free node identity using a `NodeType` enum + record ID pair (`NodeRef`), replacing raw integer IDs across the full stack (API responses, frontend state, query keys, DnD system). Eliminates the polymorphic parent ID collision risk between pages and elements
- **GitHub Actions CI workflow** — PHP QA (PHPStan + test coverage), JS QA (Biome + Stylelint + typecheck + Vitest), E2E tests (Playwright on Chromium), and i18n parity checks run on every push and PR

### Changed

- **oxlint replaced with Biome** — JS/TS linting and formatting now use Biome (`biome.json`) instead of oxlint, with `format:check` added to the QA pipeline and a11y rules raised to error severity
- **`useTreeEnrichment` hook replaced with collapse-state context** — collapse state management extracted into a dedicated `useCollapseState` hook and context, removing the enrichment layer
- **Block components split into editable/readonly variants** — `SectionBlock`, `RowBlock`, `ColumnBlock`, and `ElementCard` render distinct editable vs readonly component trees based on `ReadonlyContext`
- **Element content preview removed from grid editor** — grid cards no longer render inline content previews, simplifying the editor UI
- **API versioning moved to path parameter** — version identifier sent as a URL path segment instead of a query string parameter
- **Template rendering uses `data-element` attributes** — element type identification in templates switched from CSS classes to `data-element` attributes; holder class generation consolidated via `getHolderClasses()`
- **Section template wraps content in container div** — `Section_holder.ss` now wraps its children in a container `<div>` for consistent layout control
- **`GridEditorField` switched to `FormField` base** — replaced `GridField` parent with `FormField`, adding `schemaComponent` for React FormBuilder rendering and Injector registration via `GridEditorField` wrapper component
- **Typed `ValidationErrorCode` enum** — validation errors use a dedicated enum instead of raw strings, improving error handling consistency

### Fixed

- `findByParents` repository query not pair-matching `ParentClass` + `ParentID`, allowing false matches across polymorphic parent types
- `ReorderValidator` same-parent shortcut not comparing `ParentClass`, allowing invalid cross-type reorders to bypass validation
- Section/Row auto-scaffold not wrapped in a database transaction, risking partial tree creation on failure
- Zero and negative column widths accepted by grid settings validation
- `DBGridSettings` treating zero-width JSON payloads as valid instead of null
- `GridSettings` serializer not validating required keys in JSON, accepting malformed payloads
- Bulma adapter emitting incorrect `is-hidden` utility classes for visibility toggling
- Missing `aspect_ratio_classes` mapping in adapter silently returning empty string instead of throwing
- `ContentLayoutAdapterInterface` not bound to `GridAdapterInterface` singleton in DI config
- Grid editor not mounting in Pjax-loaded CMS forms — switched from entwine `onmatch` to `MutationObserver`
- `ElementCard` rendered as `<div>` instead of `<a>`, breaking link semantics and keyboard navigation
- Roving focus and focus restoration broken in listbox and menu widgets
- Column header text overflowing card at narrow widths
- `DuplicateToDialog` back button not skipping auto-advanced zone step
- Mutation error toasts inconsistent across publish/unpublish actions
- Reorder query cache invalidated on failure, causing rollback flicker
- `DELETE` requests sending parameters as JSON body instead of query string
- Fixture reset endpoint missing `confirm=1` guard, allowing accidental resets
- `Result` template type not marked as covariant
- Interactive elements inside `ElementCard` anchor not calling `preventDefault`, causing unintended navigation

### Performance

- Playwright browser binaries cached in CI for faster E2E runs

### Dependencies

- `@tanstack/react-query` 5.96 → 5.99
- Vite 8.0.7 → 8.0.8
- Vitest 4.1.3 → 4.1.4
- Stylelint 17.6 → 17.8
- `@types/node` 25.5 → 25.6
- `@stryker-mutator/vitest-runner` 9.5 → 9.6
- `@stryker-mutator/typescript-checker` 9.5 → 9.6
- `@biomejs/biome` 2.4 (new, replaces `oxlint`)
- Removed `oxlint`
- Added `js-yaml` ^4.1 (dev, for i18n parity script)

### Developer Experience

- **Biome as unified JS/TS linter + formatter** — replaces oxlint; `npm run format` / `format:check` added; `biome.json` configured with a11y rules at error severity and `noNonNullAssertion` disabled for test files
- **i18n CI pipeline** — `npm run i18n:check` validates JS key collection (dry-run) and EN/NL translation parity; integrated into `npm run qa` and `make qa`
- **`make qa` parallelised and reporting improved** — parallel execution fixed and progress output added
- **E2E test coverage expanded** — new specs for version history readonly grid, viewport cascade overrides, and validation error display; media-elements flake stabilised via Chosen jQuery helper
- **Frontend test rewrite for NodeRef identity** — all frontend tests updated to use `NodeRef`/`NodeKey` model; `enrichedFactories` removed in favour of unified `factories.ts`
- **Bridge test coverage** — new unit tests for Injector, entwine, and `gridSettingsField` bridges
- **Fluent test coverage** — `GridAwareDeleteLocalisationPolicy` delete lifecycle covered

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

[6.0.0-alpha.6]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.6
[6.0.0-alpha.5]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.5
[6.0.0-alpha.4]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.4
[6.0.0-alpha.3]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.3
[6.0.0-alpha.2]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.2
[6.0.0-alpha.1]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.1
