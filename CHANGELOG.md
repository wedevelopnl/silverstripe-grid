# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[6.0.0-alpha.2]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.2
[6.0.0-alpha.1]: https://github.com/wedevelopnl/silverstripe-grid/releases/tag/6.0.0-alpha.1
