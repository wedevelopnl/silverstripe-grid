# Fluent (Localisation) Support Design

## Problem

SilverStripe sites using the Fluent module for multi-locale content need the grid system to work correctly with independent content structures per locale. The module must support Fluent out of the box without additional code changes, but Fluent must remain an optional dependency since most sites won't use it.

## Approach: FluentIsolatedExtension on GridElement

Fluent's `FluentIsolatedExtension` adds a `LocaleID` foreign key directly to the DataObject table, scoping each record to exactly one locale. Fluent's `augmentSQL` automatically appends `WHERE LocaleID = ?` to all queries, including in the CMS. This gives each locale a fully independent grid tree without any changes to the module's query, service, or controller layers.

### Why FluentIsolatedExtension over alternatives

- **FluentFilteredExtension** (record visibility via many_many junction): disables filtering in CMS by default, requiring custom CMS-side filtering. Also allows records in multiple locales, which contradicts the independent-structures requirement.
- **FluentExtension** (field-level translation via localised tables): shares structure across locales with translated fields. Doesn't support independent grid layouts per locale.
- **FluentIsolatedExtension**: one record = one locale, filters in CMS by default, minimal schema impact (single FK column), simple deletion semantics. Purpose-built for this use case.

## Design

### 1. Extension Architecture

#### No shipped YAML config

The module does NOT ship any Fluent-related YAML configuration. Enabling Fluent support is the implementor's responsibility. The README documents how to add project-level YAML to wire the extensions.

The implementor adds to their project-level YAML:

```yaml
---
Name: project-grid-fluent
Only:
  classexists: TractorCow\Fluent\Extension\FluentIsolatedExtension
---
WeDevelop\Grid\Model\GridElement:
  extensions:
    FluentIsolated: TractorCow\Fluent\Extension\FluentIsolatedExtension
    FluentLocale: WeDevelop\Grid\Extensions\FluentLocaleExtension
```

Applied to `GridElement` (the abstract base class), both extensions propagate to all subclasses: Section, Row, Column, ContentElement, and any third-party elements extending GridElement.

When Fluent is installed but not configured, the module behaves identically to a non-Fluent installation. The implementor opts in explicitly.

#### FluentLocaleExtension (auto-locale assignment)

A small `DataExtension` shipped in `src/Extensions/FluentLocaleExtension.php`. Applied alongside `FluentIsolatedExtension` by the implementor.

Responsibility: ensure every element has a locale on write.

```
onBeforeWrite:
  1. if !$this->owner->hasExtension(FluentIsolatedExtension::class) -> return
  2. if $this->owner->LocaleID > 0 -> return (already set)
  3. resolve locale from FluentState::singleton()->getLocale()
  4. look up Locale record by locale code
  5. assign LocaleID
```

This solves three problems:
- **Auto-scaffolding**: Section -> Row -> Column cascade all inherit the active locale without changes to scaffolding code
- **API creates**: Elements created via the grid editor API inherit the CMS locale
- **Test compatibility**: Test code creating elements without explicit locale gets valid records

The extension imports Fluent classes (`FluentState`, `Locale`), which is safe because it's only applied when the implementor explicitly wires it via YAML -- Fluent is guaranteed installed at that point.

### 2. What Does Not Change

The entire Fluent integration consists of one PHP extension class + README documentation + test infrastructure. No changes to:

- **GridTreeBuilder** -- Fluent's `augmentSQL` filters queries transparently
- **OrmGridElementRepository** -- batch queries via `findByParents()` get the locale WHERE clause appended by Fluent's ORM layer
- **GridController** -- FluentState is set by Fluent's middleware before endpoints run
- **GridNodeMapper** -- reads from already-locale-scoped DataObjects
- **React frontend** -- zero changes; no locale parameters, no cache key changes, no new types; Fluent middleware sets locale via session/cookie, the server handles everything
- **Templates** -- records are locale-scoped, so `$Title` etc. return the correct value
- **Auto-scaffolding** (Section/Row `onAfterWrite`) -- writes happen within the active FluentState, and `FluentLocaleExtension::onBeforeWrite` assigns the locale automatically
- **Hierarchy validation** -- checks parent/child allowances, which are structural, not locale-dependent
- **ReorderService** -- reordering happens within a locale-scoped query context

### 3. Composer Configuration

Fluent goes in `suggest` -- no `require`, no `require-dev`:

```json
{
  "suggest": {
    "tractorcow/silverstripe-fluent": "Required for multi-locale support with isolated element records per locale"
  }
}
```

### 4. Testing Strategy

#### Test organization

- `tests/Unit/` -- unchanged, no DB, no Fluent concern
- `tests/Integration/` -- core integration tests, must pass in both environments (with and without Fluent)
- `tests/Integration/Fluent/` -- Fluent-specific tests, skipped when Fluent is not installed

#### Core tests in Fluent environment

Because the module ships no Fluent YAML config, and core tests don't use `extra_extensions` to add Fluent extensions, core tests run identically whether Fluent is installed or not. Fluent is present but inert -- no "core tests break when Fluent is installed" problem.

#### Fluent integration tests

Test the actual Fluent behavior using `extra_extensions` on `SapphireTest` to apply both `FluentIsolatedExtension` and `FluentLocaleExtension` to `GridElement`. Skip via `markTestSkipped` when Fluent is not installed.

Test scenarios:
- Elements created in locale A are invisible when querying in locale B
- Auto-scaffolding (Section -> Row -> Column) all receive the active locale's `LocaleID`
- Tree builder returns the correct locale-scoped tree for each locale
- Switching FluentState produces independent trees for the same page and zone
- Auto-locale assignment sets `LocaleID` on write when unset
- Guard: extension bails out when `FluentIsolatedExtension` is not active on the DataObject

#### Docker setup

Two isolated Docker Compose services/profiles:
- **Standard** (existing): no Fluent installed
- **Fluent**: extends the standard service, adds `tractorcow/silverstripe-fluent` as a Composer dependency

No install/teardown in the same container -- full isolation between environments.

#### Makefile targets

- `make test-integration` -- runs core tests in standard service (unchanged)
- `make test-fluent` -- runs all integration tests (core + Fluent-specific) in the Fluent service

#### CI pipeline

Two matrix entries using the respective Docker services. Both run on every push/PR.

## Locale Propagation (How It Works End-to-End)

For reference, this is how locale state flows through the system when Fluent is active. No custom code is needed for any of this -- it's all handled by Fluent's middleware and ORM layer.

1. Author selects locale in CMS locale switcher (Fluent's UI)
2. Full page reload with `?l=locale_code` query parameter
3. `DetectLocaleMiddleware` detects locale, stores in session (`FluentLocale_CMS`) and cookie
4. `FluentState` singleton is set with the active locale
5. Subsequent AJAX requests (including grid editor API calls) restore locale from session -- no explicit locale parameter needed
6. `FluentIsolatedExtension::augmentSQL()` appends `WHERE LocaleID = ?` to all GridElement queries
7. Grid editor receives locale-scoped element tree with correct titles/content
8. Element writes (creates, duplicates, auto-scaffolding) go through `FluentLocaleExtension::onBeforeWrite()` which assigns the active locale's ID
